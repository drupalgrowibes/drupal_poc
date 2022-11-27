<?php

namespace Drupal\server_general\Plugin\EntityViewBuilder;

use Drupal\node\NodeInterface;
use Drupal\server_general\EntityViewBuilder\NodeViewBuilderAbstract;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\og\OgAccessInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\og\OgMembershipInterface;
use Drupal\Core\Routing\RedirectDestinationTrait;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * The "Node Group" plugin.
 *
 * @EntityViewBuilder(
 *   id = "node.group",
 *   label = @Translation("Node - Group"),
 *   description = "Node view builder for Group bundle."
 * )
 */
class NodeGroup extends NodeViewBuilderAbstract {

  use RedirectDestinationTrait;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The OG access service.
   *
   * @var \Drupal\og\OgAccessInterface
   */
  protected $ogAccess;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new nodegroup object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\og\OgAccessInterface $og_access
   *   The OG access service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(AccountInterface $current_user, OgAccessInterface $og_access, EntityTypeManagerInterface $entity_type_manager) {
    $this->currentUser = $current_user;
    $this->ogAccess = $og_access;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('current_user'),
      $container->get('og.access'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Build full view mode.
   *
   * @param array $build
   *   The existing build.
   * @param \Drupal\node\NodeInterface $entity
   *   The entity.
   *
   * @return array
   *   Render array.
   */
  public function buildFull(array $build, NodeInterface $entity): array {

    // Cache by the OG membership state. Anonymous users are handled below.
    $build['#cache']['contexts'] = [
      'og_membership_state',
      'user.roles:authenticated',
    ];
    $cache_meta = CacheableMetadata::createFromRenderArray($build);

    $entity_type_id = $entity->getEntityTypeId();
    $cache_meta->merge(CacheableMetadata::createFromObject($entity));
    $cache_meta->applyTo($build);

    $user = $this->entityTypeManager->getStorage('user')->load(($this->currentUser->id()));

    if ($entity->getOwnerId() == $user->id()) {
      // User is the group manager.
      $build[] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'title' => $this->t('You are the group manager'),
          'class' => ['group', 'manager'],
        ],
        '#value' => $this->t('You are the group manager'),
      ];

      return $build;
    }

    $storage = $this->entityTypeManager->getStorage('og_membership');
    $props = [
      'uid' => $user ? $user->id() : 0,
      'entity_type' => $entity->getEntityTypeId(),
      'entity_bundle' => $entity->bundle(),
      'entity_id' => $entity->id(),
    ];

    $memberships = $storage->loadByProperties($props);
    /** @var \Drupal\og\OgMembershipInterface $membership */
    $membership = reset($memberships);

    if ($membership) {
      $cache_meta->merge(CacheableMetadata::createFromObject($membership));
      $cache_meta->applyTo($build);

      if ($membership->isBlocked()) {
        // If user is blocked, they should not be able to apply for
        // membership.
        return $build;
      }
      // Member is pending or active.
      $link['title'] = $this->t('Unsubscribe from group');
      $link['url'] = Url::fromRoute('og.unsubscribe', [
        'entity_type_id' => $entity_type_id,
        'group' => $entity->id(),
      ]);
      $link['class'] = ['custom-unsubscribe'];
    }
    else {
      // If the user is authenticated, set up the subscribe link.
      if ($user->isAuthenticated()) {
        $parameters = [
          'entity_type_id' => $entity->getEntityTypeId(),
          'group' => $entity->id(),
          'og_membership_type' => OgMembershipInterface::TYPE_DEFAULT,
        ];

        $url = Url::fromRoute('og.subscribe', $parameters);
        $title = $this->t('Hi @username, click here if you would like to subscribe to this group called @title', ['@username' => $user->get('name')->value, '@title' => $entity->getTitle()]);
      }
      else {

        // User is anonymous, link to user login and redirect back to here.
        $url = Url::fromRoute('user.login', [], ['query' => $this->getDestinationArray()]);
        $title = $this->t('Login to subscribe');
      }

      /** @var \Drupal\Core\Access\AccessResult $access */
      if (($access = $this->ogAccess->userAccess($entity, 'subscribe without approval', $user)) && $access->isAllowed()) {
        $link['title'] = $title;
        $link['class'] = ['custom-subscribe'];
        $link['url'] = $url;
      }
      elseif (($access = $this->ogAccess->userAccess($entity, 'subscribe', $user)) && $access->isAllowed()) {
        $link['title'] = $title;
        $link['class'] = ['custom-subscribe', 'custom-request'];
        $link['url'] = $url;
      }
      else {
        $build[] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => [
            'title' => $this->t('This is a closed group. Only a group administrator can add you.'),
            'class' => ['group', 'closed'],
          ],
          '#value' => $this->t('This is a closed group. Only a group administrator can add you.'),
        ];

        return $build;
      }
    }

    if (!empty($link['title'])) {
      $link += [
        'options' => [
          'attributes' => [
            'title' => $link['title'],
            'class' => ['group'] + $link['class'],
          ],
        ],
      ];

      $build[] = [
        '#type' => 'link',
        '#title' => $link['title'],
        '#url' => $link['url'],
      ];
    }
    return $build;
  }

}
