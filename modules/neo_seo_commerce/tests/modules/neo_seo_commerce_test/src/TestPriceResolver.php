<?php

declare(strict_types=1);

namespace Drupal\neo_seo_commerce_test;

use Drupal\commerce\Context;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\Resolver\PriceResolverInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;

/**
 * Prices as a shop with a sale and a members' price would.
 *
 * A SKU starting `SALE-` is a fifth off for everyone, as a promotion or price
 * list would make it. A signed-in customer pays 5 less again: a price no
 * anonymous visitor sees.
 */
class TestPriceResolver implements PriceResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(PurchasableEntityInterface $entity, $quantity, Context $context) {
    if (!$entity instanceof ProductVariationInterface || !$entity->getPrice()) {
      return NULL;
    }
    $price = $entity->getPrice();
    if (str_starts_with((string) $entity->getSku(), 'SALE-')) {
      $price = $price->multiply('0.8');
    }
    if ($context->getCustomer()->isAuthenticated()) {
      $price = $price->subtract(new Price('5', $price->getCurrencyCode()));
    }
    return $price;
  }

}
