<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Graph;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\neo_seo\Mapping\BundleMappingRepository;
use Drupal\neo_seo\MediaImage;

/**
 * The people on a page: the person a profile page is about, and the authors.
 *
 * A person is any entity whose bundle is mapped as a ProfilePage. Each is
 * described once, under one @id keyed by uuid, so the person on their own
 * profile and the same person as an author are the same node.
 */
class People {

  /**
   * Constructs the people service.
   *
   * @param \Drupal\neo_seo\Mapping\BundleMappingRepository $mapping
   *   The bundle mapping repository.
   * @param \Drupal\neo_seo\Graph\SchemaIds $ids
   *   The id service.
   * @param \Drupal\neo_seo\MediaImage $mediaImage
   *   The media image helper.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository, for translations.
   */
  public function __construct(
    protected BundleMappingRepository $mapping,
    protected SchemaIds $ids,
    protected MediaImage $mediaImage,
    protected EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Whether an entity is a person: its bundle is mapped as a ProfilePage.
   */
  public function isPerson(ContentEntityInterface $entity): bool {
    return $this->mapping->forBundle($entity->getEntityTypeId(), $entity->bundle())->pageType === 'ProfilePage';
  }

  /**
   * The person a profile page is about: the page's own entity.
   */
  public function profile(PageContext $context): ?ContentEntityInterface {
    $entity = $context->entity;
    return $entity && $this->isPerson($entity) ? $entity : NULL;
  }

  /**
   * The authors of a page: the visible people its author field references.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   The authors, in field order, without duplicates.
   */
  public function authors(PageContext $context): array {
    $entity = $context->entity;
    $field = $this->mapping->forPage($context)->authorField;
    if (!$entity || $field === '' || !$entity->hasField($field)) {
      return [];
    }
    $authors = [];
    foreach ($entity->get($field) as $item) {
      $target = $item->entity ?? NULL;
      if (!$target instanceof ContentEntityInterface || isset($authors[$target->uuid()])) {
        continue;
      }
      $access = $target->access('view', NULL, TRUE);
      $context->addCacheableDependency($access);
      if (!$access->isAllowed() || !$this->isPerson($target)) {
        continue;
      }
      $authors[$target->uuid()] = $this->entityRepository->getTranslationFromContext($target, $context->langcode);
    }
    return array_values($authors);
  }

  /**
   * The id of a person.
   */
  public function id(PageContext $context, ContentEntityInterface $person): string {
    return $this->ids->person($context, $person->uuid());
  }

  /**
   * The Person node for an entity.
   *
   * @return array<string, mixed>
   *   The node.
   */
  public function node(PageContext $context, ContentEntityInterface $person): array {
    $context->addCacheableDependency($person);
    $mapping = $this->mapping->forBundle($person->getEntityTypeId(), $person->bundle());
    $image = NULL;
    if ($mapping->imageField && $person->hasField($mapping->imageField)) {
      $target = $person->get($mapping->imageField)->entity;
      $image = $target ? $this->mediaImage->resolve((int) $target->id(), $context) : NULL;
    }
    $url = $person->hasLinkTemplate('canonical') ? $person->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE) : NULL;
    if ($url) {
      $context->addCacheableDependency($url);
    }
    return [
      '@type' => 'Person',
      '@id' => $this->id($context, $person),
      'name' => (string) $person->label(),
      'url' => $url?->getGeneratedUrl(),
      'jobTitle' => $this->text($person, $mapping->jobTitleField),
      'image' => $image ? [
        '@type' => 'ImageObject',
        'url' => $image['url'],
        'contentUrl' => $image['url'],
        'caption' => $image['caption'],
      ] : NULL,
      'sameAs' => $this->links($person, $mapping->sameAsField),
      'worksFor' => SchemaIds::ref($this->ids->siteIdentity($context)),
    ];
  }

  /**
   * The first value of a text field, or NULL.
   */
  protected function text(FieldableEntityInterface $entity, string $field): ?string {
    if ($field === '' || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }
    return trim(strip_tags((string) $entity->get($field)->first()->getString())) ?: NULL;
  }

  /**
   * The absolute URLs of a link field.
   *
   * @return string[]
   *   The URLs.
   */
  protected function links(FieldableEntityInterface $entity, string $field): array {
    if ($field === '' || !$entity->hasField($field)) {
      return [];
    }
    $urls = [];
    foreach ($entity->get($field) as $item) {
      $uri = (string) ($item->uri ?? $item->value ?? '');
      if (UrlHelper::isExternal($uri) && UrlHelper::isValid($uri, TRUE)) {
        $urls[] = $uri;
      }
    }
    return $urls;
  }

}
