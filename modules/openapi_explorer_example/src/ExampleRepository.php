<?php

namespace Drupal\openapi_explorer_example;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Queries the sample node and user listings.
 *
 * Shared by the controller routes and the REST resources so that both expose
 * exactly the same filters and the same response shape, which is also what the
 * annotations on each of them describe.
 */
class ExampleRepository {

  /**
   * Number of items returned when no limit is given.
   */
  const DEFAULT_LIMIT = 20;

  /**
   * Largest page size a caller may ask for.
   */
  const MAX_LIMIT = 100;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Cache of the taxonomy reference fields available on nodes.
   *
   * @var string[]|null
   */
  protected $tagFields = NULL;

  /**
   * Constructs the repository.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * Returns a page of published nodes.
   *
   * @param array $query
   *   The raw query parameters: type, tags, tags_field, page, limit.
   *
   * @return array
   *   ['data' => [...], 'meta' => [...]].
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When a filter value is not usable on this site.
   */
  public function nodes(array $query): array {
    $types = $this->listParam($query['type'] ?? NULL);
    $tags = $this->listParam($query['tags'] ?? NULL);
    $tagField = $this->resolveTagField($query['tags_field'] ?? NULL, (bool) $tags);
    [$page, $limit] = $this->pager($query);

    if ($types) {
      $this->assertBundles($types);
    }
    $termIds = $tags ? $this->resolveTermIds($tags) : [];

    $total = (int) $this->nodeQuery($types, $termIds, $tagField)->count()->execute();
    $ids = $this->nodeQuery($types, $termIds, $tagField)
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC')
      ->range($page * $limit, $limit)
      ->execute();

    $storage = $this->entityTypeManager->getStorage('node');
    $items = [];
    foreach ($ids ? $storage->loadMultiple($ids) : [] as $node) {
      $items[] = $this->nodeSummary($node, $tagField);
    }

    return [
      'data' => $items,
      'meta' => $this->meta($total, count($items), $page, $limit),
    ];
  }

  /**
   * Returns a page of active users.
   *
   * @param array $query
   *   The raw query parameters: roles, status, page, limit.
   *
   * @return array
   *   ['data' => [...], 'meta' => [...]].
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When a filter value is not usable on this site.
   */
  public function users(array $query): array {
    $roles = $this->listParam($query['roles'] ?? NULL);
    $status = array_key_exists('status', $query) ? $this->boolParam($query['status'], 'status') : NULL;
    [$page, $limit] = $this->pager($query);

    if ($roles) {
      $this->assertRoles($roles);
    }

    $total = (int) $this->userQuery($roles, $status)->count()->execute();
    $ids = $this->userQuery($roles, $status)
      ->sort('created', 'DESC')
      ->sort('uid', 'DESC')
      ->range($page * $limit, $limit)
      ->execute();

    $storage = $this->entityTypeManager->getStorage('user');
    $items = [];
    foreach ($ids ? $storage->loadMultiple($ids) : [] as $account) {
      $items[] = $this->userSummary($account);
    }

    return [
      'data' => $items,
      'meta' => $this->meta($total, count($items), $page, $limit),
    ];
  }

  /**
   * The taxonomy reference fields that nodes on this site actually have.
   *
   * @return string[]
   *   Field machine names.
   */
  public function tagFields(): array {
    if ($this->tagFields !== NULL) {
      return $this->tagFields;
    }
    $fields = [];
    foreach ($this->entityFieldManager->getFieldStorageDefinitions('node') as $name => $definition) {
      if ($this->targetsTerms($definition)) {
        $fields[] = $name;
      }
    }
    sort($fields);
    return $this->tagFields = $fields;
  }

  /**
   * Builds the node query for the given filters.
   *
   * The query is rebuilt rather than cloned, because counting and listing both
   * consume it.
   *
   * @param string[] $types
   *   Content type machine names.
   * @param int[] $term_ids
   *   Taxonomy term ids, empty when no tag filter applies.
   * @param string|null $tag_field
   *   The taxonomy field to filter on.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The query.
   */
  protected function nodeQuery(array $types, array $term_ids, ?string $tag_field) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1);
    if ($types) {
      $query->condition('type', $types, 'IN');
    }
    if ($term_ids && $tag_field !== NULL) {
      $query->condition($tag_field . '.target_id', $term_ids, 'IN');
    }
    return $query;
  }

  /**
   * Builds the user query for the given filters.
   *
   * @param string[] $roles
   *   Role machine names.
   * @param bool|null $status
   *   Whether to filter on the blocked/active flag.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The query.
   */
  protected function userQuery(array $roles, ?bool $status) {
    $query = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(TRUE)
      // Exclude the anonymous user, which is not a real account.
      ->condition('uid', 0, '>');
    if ($roles) {
      $query->condition('roles', $roles, 'IN');
    }
    if ($status !== NULL) {
      $query->condition('status', $status ? 1 : 0);
    }
    return $query;
  }

  /**
   * Projects a node onto the documented summary shape.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string|null $tag_field
   *   The taxonomy field to read tags from, when the site has one.
   *
   * @return array
   *   The summary.
   */
  protected function nodeSummary($node, ?string $tag_field): array {
    $tags = [];
    if ($tag_field !== NULL && $node->hasField($tag_field)) {
      foreach ($node->get($tag_field)->referencedEntities() as $term) {
        $tags[] = ['id' => (int) $term->id(), 'name' => $term->label()];
      }
    }
    $author = $node->getOwner();
    return [
      'id' => (int) $node->id(),
      'uuid' => $node->uuid(),
      'type' => $node->bundle(),
      'title' => $node->label(),
      'langcode' => $node->language()->getId(),
      // API payloads use UTC ISO 8601 so the value is unambiguous, rather than
      // the site's display timezone.
      'created' => gmdate('c', (int) $node->getCreatedTime()),
      'changed' => gmdate('c', (int) $node->getChangedTime()),
      'author' => $author ? $author->getDisplayName() : NULL,
      'url' => $node->toUrl('canonical')->toString(TRUE)->getGeneratedUrl(),
      'tags' => $tags,
    ];
  }

  /**
   * Projects a user onto the documented summary shape.
   *
   * Deliberately narrow: no email address or other personal data.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   *
   * @return array
   *   The summary.
   */
  protected function userSummary($account): array {
    return [
      'id' => (int) $account->id(),
      'uuid' => $account->uuid(),
      'name' => $account->getAccountName(),
      'display_name' => (string) $account->getDisplayName(),
      'status' => (bool) $account->isActive(),
      'created' => gmdate('c', (int) $account->getCreatedTime()),
      'roles' => array_values($account->getRoles()),
    ];
  }

  /**
   * Builds the pagination metadata.
   *
   * @param int $total
   *   Total matching items.
   * @param int $count
   *   Items on this page.
   * @param int $page
   *   The current page index.
   * @param int $limit
   *   The page size.
   *
   * @return array
   *   The metadata.
   */
  protected function meta(int $total, int $count, int $page, int $limit): array {
    return [
      'total' => $total,
      'count' => $count,
      'page' => $page,
      'per_page' => $limit,
      'pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
    ];
  }

  /**
   * Splits a comma-separated query value into trimmed, non-empty values.
   *
   * @param mixed $value
   *   The raw query value.
   *
   * @return string[]
   *   The values.
   */
  protected function listParam($value): array {
    if ($value === NULL || $value === '') {
      return [];
    }
    $values = is_array($value) ? $value : explode(',', (string) $value);
    $out = [];
    foreach ($values as $item) {
      $item = trim((string) $item);
      if ($item !== '') {
        $out[] = $item;
      }
    }
    return array_values(array_unique($out));
  }

  /**
   * Reads a boolean query value.
   *
   * @param mixed $value
   *   The raw query value.
   * @param string $name
   *   The parameter name, for the error message.
   *
   * @return bool
   *   The boolean value.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the value is not a recognised boolean.
   */
  protected function boolParam($value, string $name): bool {
    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['1', 'true', 'yes'], TRUE)) {
      return TRUE;
    }
    if (in_array($normalized, ['0', 'false', 'no'], TRUE)) {
      return FALSE;
    }
    throw new BadRequestHttpException(sprintf('The "%s" parameter must be one of: 1, 0, true, false, yes, no.', $name));
  }

  /**
   * Reads and clamps the page and limit parameters.
   *
   * @param array $query
   *   The raw query parameters.
   *
   * @return array
   *   [int $page, int $limit].
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When either value is not a non-negative integer.
   */
  protected function pager(array $query): array {
    $page = $this->intParam($query['page'] ?? NULL, 'page', 0);
    $limit = $this->intParam($query['limit'] ?? NULL, 'limit', self::DEFAULT_LIMIT);
    if ($limit < 1) {
      $limit = self::DEFAULT_LIMIT;
    }
    return [$page, min($limit, self::MAX_LIMIT)];
  }

  /**
   * Reads a non-negative integer query value.
   *
   * @param mixed $value
   *   The raw query value.
   * @param string $name
   *   The parameter name, for the error message.
   * @param int $default
   *   The value to use when the parameter is absent.
   *
   * @return int
   *   The integer value.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the value is not a non-negative integer.
   */
  protected function intParam($value, string $name, int $default): int {
    if ($value === NULL || $value === '') {
      return $default;
    }
    if (!ctype_digit((string) $value)) {
      throw new BadRequestHttpException(sprintf('The "%s" parameter must be a non-negative integer.', $name));
    }
    return (int) $value;
  }

  /**
   * Decides which taxonomy field the tag filter applies to.
   *
   * @param string|null $requested
   *   The field named by the caller, if any.
   * @param bool $required
   *   Whether a field must be resolved because a tag filter was supplied.
   *
   * @return string|null
   *   The field machine name, or NULL when no filter is needed.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When no usable field can be determined.
   */
  protected function resolveTagField(?string $requested, bool $required): ?string {
    $available = $this->tagFields();
    $requested = $requested !== NULL ? trim($requested) : '';

    if ($requested !== '') {
      if (!in_array($requested, $available, TRUE)) {
        throw new BadRequestHttpException(sprintf(
          'Unknown tags field "%s". Taxonomy fields on this site: %s',
          $requested,
          $available ? implode(', ', $available) : 'none'
        ));
      }
      return $requested;
    }

    if (in_array('field_tags', $available, TRUE)) {
      return 'field_tags';
    }
    if (!$required) {
      return $available[0] ?? NULL;
    }
    throw new BadRequestHttpException(sprintf(
      'This site has no "field_tags" field on nodes, so the tags filter needs an explicit "tags_field". Taxonomy fields available: %s',
      $available ? implode(', ', $available) : 'none'
    ));
  }

  /**
   * Resolves tag values, given as term ids or names, to term ids.
   *
   * @param string[] $values
   *   Term ids or names.
   *
   * @return int[]
   *   Term ids; a non-existent id when nothing matched, so that the filter
   *   returns an empty list rather than silently matching everything.
   */
  protected function resolveTermIds(array $values): array {
    $ids = [];
    $names = [];
    foreach ($values as $value) {
      if (ctype_digit($value)) {
        $ids[] = (int) $value;
      }
      else {
        $names[] = $value;
      }
    }
    if ($names) {
      $matches = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
        ->accessCheck(TRUE)
        ->condition('name', $names, 'IN')
        ->execute();
      foreach ($matches as $id) {
        $ids[] = (int) $id;
      }
    }
    return $ids ? array_values(array_unique($ids)) : [0];
  }

  /**
   * Rejects unknown content types.
   *
   * @param string[] $types
   *   The requested content type machine names.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When one of them does not exist.
   */
  protected function assertBundles(array $types): void {
    $known = array_keys($this->entityTypeManager->getStorage('node_type')->loadMultiple());
    $unknown = array_diff($types, $known);
    if ($unknown) {
      throw new BadRequestHttpException(sprintf(
        'Unknown content type(s): %s. Available: %s',
        implode(', ', $unknown),
        implode(', ', $known)
      ));
    }
  }

  /**
   * Rejects unknown roles.
   *
   * @param string[] $roles
   *   The requested role machine names.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When one of them does not exist.
   */
  protected function assertRoles(array $roles): void {
    $known = array_keys($this->entityTypeManager->getStorage('user_role')->loadMultiple());
    $unknown = array_diff($roles, $known);
    if ($unknown) {
      throw new BadRequestHttpException(sprintf(
        'Unknown role(s): %s. Available: %s',
        implode(', ', $unknown),
        implode(', ', $known)
      ));
    }
  }

  /**
   * Whether a field storage definition references taxonomy terms.
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $definition
   *   The field storage definition.
   *
   * @return bool
   *   TRUE when the field points at taxonomy terms.
   */
  protected function targetsTerms(FieldStorageDefinitionInterface $definition): bool {
    return $definition->getType() === 'entity_reference'
      && $definition->getSetting('target_type') === 'taxonomy_term';
  }

}
