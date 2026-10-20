<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\token\TokenInterface;
use Drupal\token\TreeBuilderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The tokens a snippet input offers, names and checks.
 *
 * `?types[]=node&q=sub` searches every token of those types and the global
 * ones (site, current date…), three levels deep, by token or name.
 * `?types[]=node&check[]=[node:field_subtitle]` names tokens already in a
 * field — from the token info, or built from their first and last parts for a
 * deeper chain — and says which do not resolve for those types.
 */
class TokensController implements ContainerInjectionInterface {

  use AutowireTrait;

  /**
   * The most tokens a search returns.
   */
  public const LIMIT = 50;

  /**
   * How deep a search reaches into chained tokens.
   */
  public const DEPTH = 3;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\token\TreeBuilderInterface $treeBuilder
   *   The token module's tree builder.
   * @param \Drupal\token\TokenInterface $token
   *   The token service, as the token module replaces it.
   */
  public function __construct(
    #[Autowire(service: 'token.tree_builder')]
    protected TreeBuilderInterface $treeBuilder,
    #[Autowire(service: 'token')]
    protected TokenInterface $token,
  ) {}

  /**
   * Searches, or names and checks, as JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   {tokens: [{token, name, description}]} for a search; {names: {token:
   *   name}, invalid: [token]} for a check.
   */
  public function lookup(Request $request): JsonResponse {
    $query = $request->query->all();
    $types = $this->types((array) ($query['types'] ?? []));
    if (isset($query['check'])) {
      $check = array_values(array_filter(
        (array) $query['check'],
        static fn($token) => is_string($token) && preg_match('/^\[[^\[\]\s]+\]$/', $token),
      ));
      return new JsonResponse($this->check(array_slice($check, 0, 100), $types));
    }
    return new JsonResponse(['tokens' => $this->search(trim((string) ($query['q'] ?? '')), $types)]);
  }

  /**
   * The token types asked for that exist, and the global ones.
   *
   * @return string[]
   *   Token types.
   */
  protected function types(array $asked): array {
    $types = array_filter($asked, fn($type) => is_string($type) && $this->token->getTypeInfo($type));
    return array_values(array_unique(array_merge($types, $this->token->getGlobalTokenTypes(), ['neo-seo'])));
  }

  /**
   * Tokens whose token or name holds the query.
   *
   * @return array<int, array{token: string, name: string, description: string}>
   *   At most LIMIT, shallowest first.
   */
  protected function search(string $q, array $types): array {
    $needle = mb_strtolower($q);
    $found = [];
    foreach ($types as $type) {
      foreach ($this->treeBuilder->buildTree($type, ['flat' => TRUE, 'depth' => static::DEPTH]) as $token => $info) {
        if (!empty($info['dynamic']) || !empty($info['restricted'])) {
          continue;
        }
        $name = strip_tags((string) ($info['name'] ?? ''));
        if ($needle !== '' && !str_contains(mb_strtolower($token), $needle) && !str_contains(mb_strtolower($name), $needle)) {
          continue;
        }
        $found[$token] = [
          'token' => $token,
          'name' => $name,
          'description' => strip_tags((string) ($info['description'] ?? '')),
        ];
      }
    }
    uasort($found, static fn(array $a, array $b) => substr_count($a['token'], ':') <=> substr_count($b['token'], ':') ?: strcmp($a['token'], $b['token']));
    return array_values(array_slice($found, 0, static::LIMIT));
  }

  /**
   * Names tokens, and says which do not resolve for the types.
   *
   * @return array{names: object, invalid: string[]}
   *   Token => name, and the invalid tokens.
   */
  protected function check(array $tokens, array $types): array {
    $invalid = array_values($this->token->getInvalidTokensByContext(implode(' ', $tokens), $types));
    $names = [];
    foreach ($tokens as $token) {
      $parts = explode(':', trim($token, '[]'));
      $type = array_shift($parts);
      $first = $this->token->getTokenInfo($type, $parts[0] ?? '');
      $name = $first ? strip_tags((string) $first['name']) : '';
      if ($name !== '' && count($parts) > 1) {
        // A chain: its first part's name, and what is taken from it.
        $last = end($parts);
        $name .= ': ' . ucfirst(str_replace(['_', '-'], ' ', (string) $last));
      }
      $names[$token] = $name !== '' ? $name : $token;
    }
    return ['names' => (object) $names, 'invalid' => $invalid];
  }

}
