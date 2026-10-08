<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Migration;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Lets the migration diff see a page the way it will be after the switch.
 *
 * While metatag is enabled it owns the head. A request carrying the current
 * diff token in its header is rendered with neo_seo's head instead and
 * metatag's tags stripped, never cached. The token lives in state for a few
 * minutes, is created by the drush command that fetches the pages, and is
 * removed when it is done; without it, nothing changes for anyone.
 */
class DiffMode {

  /**
   * The request header that carries the token.
   */
  public const HEADER = 'X-Neo-Seo-Diff';

  /**
   * The state key holding the token and its expiry.
   */
  protected const STATE_KEY = 'neo_seo.diff_mode';

  /**
   * How long a token is honoured, in seconds.
   */
  protected const LIFETIME = 600;

  /**
   * Constructs the diff mode.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state store.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected StateInterface $state,
    protected RequestStack $requestStack,
    protected TimeInterface $time,
  ) {}

  /**
   * Starts diff mode and returns the token a fetch must send.
   */
  public function start(): string {
    $token = Crypt::randomBytesBase64(24);
    $this->state->set(static::STATE_KEY, [
      'token' => $token,
      'expires' => $this->time->getCurrentTime() + static::LIFETIME,
    ]);
    return $token;
  }

  /**
   * Ends diff mode.
   */
  public function stop(): void {
    $this->state->delete(static::STATE_KEY);
  }

  /**
   * Whether the current request is a diff request.
   */
  public function isActive(): bool {
    $header = $this->requestStack->getCurrentRequest()?->headers->get(static::HEADER);
    if (!$header) {
      return FALSE;
    }
    $stored = $this->state->get(static::STATE_KEY);
    return is_array($stored)
      && ($stored['expires'] ?? 0) >= $this->time->getCurrentTime()
      && hash_equals((string) ($stored['token'] ?? ''), (string) $header);
  }

}
