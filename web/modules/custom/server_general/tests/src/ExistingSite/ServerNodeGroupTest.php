<?php

namespace Drupal\Tests\server_general\ExistingSite;

use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Test 'group' content type.
 */
class ServerNodeGroupTest extends ExistingSiteBase {
  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'og', 'options'];

  /**
   * Test entity group.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $group;

  /**
   * A non-author user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user1;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create node author user.
    $user = $this->createUser();

    $this->group = $this->createNode([
      'type' => 'group',
      'title' => $this->randomString(),
      'uid' => $user->id(),
    ]);
    $this->group->save();

    $this->user1 = $this->createUser();
  }

  /**
   * Test for group subscribe.
   */
  public function testNodeGroupSubscribe() {
    $this->drupalGet($this->group->toUrl());
    $this->clickLink('Login to subscribe');
    $this->assertSession()->addressEquals('user/login');

    $this->drupalLogin($this->user1);

    // Subscribe to group and verify the links.
    $this->drupalGet($this->group->toUrl());

    $user_name = $this->user1->get('name')->value;
    $group_name = $this->group->get('title')->value;
    $text = 'Hi ' . $user_name . ', click here if you would like to subscribe to this group called ' . $group_name;

    $this->assertSession()->linkExists($text);
    $this->clickLink($text);
    $this->click('#edit-submit');

    $this->drupalGet($this->group->toUrl());
    $this->assertSession()->linkExists('Unsubscribe from group');
  }

}
