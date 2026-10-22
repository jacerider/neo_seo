<?php

declare(strict_types=1);

namespace Drupal\neo_seo\SiteIdentity;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The schema.org Organization types a site may say it is.
 *
 * A short list on purpose: the types Google and the major consumers read, and
 * that a site builder can tell apart without the schema.org hierarchy open.
 * Local businesses are offered down to their specific type (an HVAC contractor
 * is an HVACBusiness), because search engines read the business type from
 * `@type` and Google asks for the most specific LocalBusiness subtype.
 */
final class OrganizationTypes {

  /**
   * The LocalBusiness types: LocalBusiness and subtypes, each may have hours.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The options.
   */
  private static function localBusiness(): array {
    return [
      'LocalBusiness' => new TranslatableMarkup('Local business'),
      'ProfessionalService' => new TranslatableMarkup('Professional service'),
      'HomeAndConstructionBusiness' => new TranslatableMarkup('Home and construction business'),
      'HVACBusiness' => new TranslatableMarkup('HVAC business'),
      'Plumber' => new TranslatableMarkup('Plumber'),
      'Electrician' => new TranslatableMarkup('Electrician'),
      'RoofingContractor' => new TranslatableMarkup('Roofing contractor'),
      'GeneralContractor' => new TranslatableMarkup('General contractor'),
      'HousePainter' => new TranslatableMarkup('House painter'),
      'Locksmith' => new TranslatableMarkup('Locksmith'),
      'MovingCompany' => new TranslatableMarkup('Moving company'),
      'AutoRepair' => new TranslatableMarkup('Auto repair'),
      'Dentist' => new TranslatableMarkup('Dentist'),
      'LegalService' => new TranslatableMarkup('Legal service'),
      'AccountingService' => new TranslatableMarkup('Accounting service'),
      'RealEstateAgent' => new TranslatableMarkup('Real estate agent'),
    ];
  }

  /**
   * The other organization types.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The options.
   */
  private static function organization(): array {
    return [
      'Organization' => new TranslatableMarkup('Organization'),
      'Corporation' => new TranslatableMarkup('Corporation'),
      'NGO' => new TranslatableMarkup('Non-governmental organization'),
      'EducationalOrganization' => new TranslatableMarkup('Educational organization'),
      'GovernmentOrganization' => new TranslatableMarkup('Government organization'),
      'MedicalOrganization' => new TranslatableMarkup('Medical organization'),
      'NewsMediaOrganization' => new TranslatableMarkup('News media organization'),
      'SportsOrganization' => new TranslatableMarkup('Sports organization'),
    ];
  }

  /**
   * The types, schema.org name => label.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The options.
   */
  public static function options(): array {
    return self::organization() + self::localBusiness();
  }

  /**
   * The types grouped for a select: organizations, then local businesses.
   *
   * @return array<string, array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>>
   *   Group label => options.
   */
  public static function grouped(): array {
    $options = static::options();
    return [
      (string) new TranslatableMarkup('Organizations') => array_intersect_key($options, self::organization()),
      (string) new TranslatableMarkup('Local businesses') => array_intersect_key($options, self::localBusiness()),
    ];
  }

  /**
   * Whether a type is one of the listed ones.
   */
  public static function isValid(string $type): bool {
    return isset(static::options()[$type]);
  }

  /**
   * Whether a type is LocalBusiness or a subtype of it.
   *
   * Schema.org defines opening hours on LocalBusiness, not on Organization.
   */
  public static function isLocalBusiness(string $type): bool {
    return isset(self::localBusiness()[$type]);
  }

}
