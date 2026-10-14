<?php

declare(strict_types=1);

namespace Drupal\neo_seo\ComponentSchema;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Url;
use Drupal\neo_seo\Graph\PageContext;

/**
 * The filters a component's `seo` declaration may apply to a value.
 *
 * Each turns a Drupal value into what schema.org expects, and gives NULL for
 * a value it cannot use, so the property is left out rather than printed
 * wrong:
 * - postal_address: an address field's value → PostalAddress;
 * - url: a link, an image, a path, a stream URI → an absolute URL;
 * - image: an image → ImageObject;
 * - text: rich text → plain text;
 * - date: a date, a timestamp → ISO 8601;
 * - slug: text → a lowercase fragment for an @id;
 * - first: a list → its first item;
 * - join: a list → its items as text, joined by the argument (", ").
 */
class Filters {

  /**
   * Every filter.
   */
  public const NAMES = ['postal_address', 'url', 'image', 'text', 'date', 'slug', 'first', 'join'];

  /**
   * Link URIs that go nowhere.
   */
  protected const NOWHERE = ['<nolink>', '<none>', '<button>', 'route:<nolink>', 'route:<none>', 'route:<button>'];

  /**
   * Constructs the filters.
   *
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator, for stream URIs.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrappers
   *   The stream wrapper manager.
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   Transliteration, for slugs.
   */
  public function __construct(
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected StreamWrapperManagerInterface $streamWrappers,
    protected TransliterationInterface $transliteration,
  ) {}

  /**
   * Applies a filter.
   *
   * @param string $name
   *   One of NAMES.
   * @param mixed $value
   *   The value.
   * @param string|null $arg
   *   The filter's argument, if it was given one.
   * @param \Drupal\neo_seo\Graph\PageContext $context
   *   The page, which collects what a generated URL depends on.
   *
   * @return mixed
   *   The filtered value; NULL when there is nothing usable.
   *
   * @throws \InvalidArgumentException
   *   For a filter that does not exist.
   */
  public function apply(string $name, mixed $value, ?string $arg, PageContext $context): mixed {
    if ($value === NULL) {
      return NULL;
    }
    return match ($name) {
      'postal_address' => $this->postalAddress($value),
      'url' => $this->url($value, $context),
      'image' => $this->image($value, $context),
      'text' => $this->text($value),
      'date' => $this->date($value),
      'slug' => $this->slug($value),
      'first' => $this->first($value),
      'join' => $this->join($value, $arg ?? ', '),
      default => throw new \InvalidArgumentException(sprintf('There is no `%s` filter. The filters: %s.', $name, implode(', ', static::NAMES))),
    };
  }

  /**
   * An address field's value as a PostalAddress.
   */
  protected function postalAddress(mixed $value): ?array {
    if (!is_array($value)) {
      return NULL;
    }
    $lines = array_filter(array_map(
      fn(string $key) => trim((string) ($value[$key] ?? '')),
      ['address_line1', 'address_line2', 'address_line3'],
    ));
    $address = array_filter([
      'streetAddress' => implode(', ', $lines),
      'addressLocality' => trim((string) ($value['locality'] ?? '')),
      'addressRegion' => trim((string) ($value['administrative_area'] ?? '')),
      'postalCode' => trim((string) ($value['postal_code'] ?? '')),
      'addressCountry' => trim((string) ($value['country_code'] ?? '')),
    ], fn(string $part) => $part !== '');
    return $address ? ['@type' => 'PostalAddress'] + $address : NULL;
  }

  /**
   * An absolute URL, from whatever holds one.
   *
   * A link the visitor may not follow (`access` FALSE) has none.
   */
  protected function url(mixed $value, PageContext $context): ?string {
    if (is_array($value)) {
      if (($value['access'] ?? TRUE) === FALSE) {
        return NULL;
      }
      $value = $value['uri'] ?? $value['url'] ?? $value['src'] ?? NULL;
    }
    if ($value instanceof Url) {
      $url = $value;
    }
    else {
      if (!is_string($value) && !$value instanceof \Stringable) {
        return NULL;
      }
      $value = trim((string) $value);
      if ($value === '' || in_array($value, static::NOWHERE, TRUE)) {
        return NULL;
      }
      if (preg_match('#^(https?:)?//#i', $value) || preg_match('#^(mailto|tel):#i', $value)) {
        return $value;
      }
      $scheme = StreamWrapperManager::getScheme($value);
      if ($scheme && $this->streamWrappers->isValidScheme($scheme)) {
        return $this->fileUrlGenerator->generateAbsoluteString($value);
      }
      try {
        $url = match (TRUE) {
          (bool) preg_match('#^(internal|entity|route|base):#', $value) => Url::fromUri($value),
          (bool) preg_match('#^[/?\#]#', $value) => Url::fromUserInput($value),
          default => NULL,
        };
      }
      catch (\InvalidArgumentException) {
        return NULL;
      }
      if (!$url) {
        return NULL;
      }
    }
    try {
      $generated = (clone $url)->setAbsolute()->toString(TRUE);
    }
    catch (\Exception) {
      return NULL;
    }
    $context->addCacheableDependency($generated);
    return $generated->getGeneratedUrl();
  }

  /**
   * An image as an ImageObject: its URL, size and alt text.
   */
  protected function image(mixed $value, PageContext $context): ?array {
    $source = is_array($value) ? ($value['uri'] ?? $value['src'] ?? $value['url'] ?? NULL) : $value;
    $url = $this->url($source, $context);
    if ($url === NULL) {
      return NULL;
    }
    $image = ['@type' => 'ImageObject', 'url' => $url, 'contentUrl' => $url];
    if (is_array($value)) {
      $image += array_filter([
        'width' => is_numeric($value['width'] ?? NULL) ? (int) $value['width'] : NULL,
        'height' => is_numeric($value['height'] ?? NULL) ? (int) $value['height'] : NULL,
        'caption' => trim((string) ($value['alt'] ?? '')) ?: NULL,
      ]);
    }
    return $image;
  }

  /**
   * Rich text as plain text: tags out, entities decoded, one line.
   */
  protected function text(mixed $value): ?string {
    if (is_array($value)) {
      $value = $value['value'] ?? NULL;
    }
    if (!is_scalar($value) && !$value instanceof \Stringable) {
      return NULL;
    }
    // A break or the end of a block separates words.
    $html = (string) preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|td|th|blockquote)>#i', ' ', (string) $value);
    $text = trim((string) preg_replace('/\s+/u', ' ', Html::decodeEntities(strip_tags($html))));
    return $text === '' ? NULL : $text;
  }

  /**
   * A date as ISO 8601; a date without a time stays a date.
   */
  protected function date(mixed $value): ?string {
    if (is_array($value)) {
      $value = $value['value'] ?? NULL;
    }
    if ($value instanceof DrupalDateTime) {
      $value = $value->getPhpDateTime();
    }
    if ($value instanceof \DateTimeInterface) {
      return $value->format('c');
    }
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
      return (new \DateTimeImmutable('@' . $value))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('c');
    }
    if (!is_string($value) && !$value instanceof \Stringable) {
      return NULL;
    }
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
      return $value;
    }
    try {
      return $value === '' ? NULL : (new \DateTimeImmutable($value))->format('c');
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Text as a lowercase, hyphenated fragment.
   */
  protected function slug(mixed $value): ?string {
    if (!is_scalar($value) && !$value instanceof \Stringable) {
      return NULL;
    }
    $text = mb_strtolower($this->transliteration->transliterate((string) $value, 'en'));
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');
    return $slug === '' ? NULL : $slug;
  }

  /**
   * A list's first item that has something in it.
   */
  protected function first(mixed $value): mixed {
    if (!is_array($value) || !array_is_list($value)) {
      return $value;
    }
    foreach ($value as $item) {
      if ($item !== NULL && $item !== '' && $item !== []) {
        return $item;
      }
    }
    return NULL;
  }

  /**
   * A list's text items, joined.
   */
  protected function join(mixed $value, string $separator): ?string {
    if (!is_array($value)) {
      return is_scalar($value) || $value instanceof \Stringable ? (string) $value : NULL;
    }
    $parts = [];
    foreach ($value as $item) {
      if (is_scalar($item) || $item instanceof \Stringable) {
        $text = trim((string) $item);
        if ($text !== '') {
          $parts[] = $text;
        }
      }
    }
    return $parts ? implode($separator, $parts) : NULL;
  }

}
