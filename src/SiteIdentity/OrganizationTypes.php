<?php

declare(strict_types=1);

namespace Drupal\neo_seo\SiteIdentity;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The schema.org Organization types a site may say it is.
 *
 * A short list on purpose: the types Google and the major consumers read, and
 * that a site builder can tell apart without the schema.org hierarchy open.
 */
final class OrganizationTypes {

  /**
   * The types, schema.org name => label.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The options.
   */
  public static function options(): array {
    return [
      'Organization' => new TranslatableMarkup('Organization'),
      'Corporation' => new TranslatableMarkup('Corporation'),
      'ProfessionalService' => new TranslatableMarkup('Professional service'),
      'LocalBusiness' => new TranslatableMarkup('Local business'),
      'NGO' => new TranslatableMarkup('Non-governmental organization'),
      'EducationalOrganization' => new TranslatableMarkup('Educational organization'),
      'GovernmentOrganization' => new TranslatableMarkup('Government organization'),
      'MedicalOrganization' => new TranslatableMarkup('Medical organization'),
      'NewsMediaOrganization' => new TranslatableMarkup('News media organization'),
      'SportsOrganization' => new TranslatableMarkup('Sports organization'),
    ];
  }

  /**
   * Whether a stored type is one of the offered types.
   */
  public static function isValid(string $type): bool {
    return isset(static::options()[$type]);
  }

}
