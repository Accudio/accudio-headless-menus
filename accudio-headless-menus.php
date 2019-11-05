<?php
/**
 * @link        https://accudio.com
 * @since       1.0.0
 * @package     Accudio_Headless_Menus
 *
 * @wordpress-plugin
 * Plugin Name:         Accudio Headless Menus
 * Plugin URI:          https://accudio.com
 * Description:         Adds menu endpoints on WP REST API
 * Version:             1.0.0
 * Author:              Alistair Shepherd — Accudio
 * Author URI:          https://accudio.com/about
 * License:             MPL-2.0
 * License URI:         https://www.mozilla.org/en-US/MPL/2.0/
 * GitHub Plugin URI:   Accudio/accudio-headless-menus
 */

class Accudio_Headless_Menus {
  /**
   * Plugin initialisation
   */
  public static function init()
  {
    add_action('rest_api_init', ['Accudio_Headless_Menus', 'register_rest']);
  }

  /**
   * Register REST endpoints
   */
  public static function register_rest()
  {
    register_rest_route('menus/v1', '/menus', array(
      'methods'  => 'GET',
      'callback' => ['Accudio_Headless_Menus', 'get_all']
    ));

    register_rest_route('menus/v1', '/menus/(?P<id>[a-zA-Z0-9_-]+)', array(
      'methods'  => 'GET',
      'callback' => ['Accudio_Headless_Menus', 'menu_data']
    ));

    register_rest_route('menus/v1', '/locations/(?P<id>[a-zA-Z0-9_-]+)', array(
      'methods'  => 'GET',
      'callback' => ['Accudio_Headless_Menus', 'location_data']
    ));

    register_rest_route('menus/v1', '/locations', array(
      'methods'  => 'GET',
      'callback' => ['Accudio_Headless_Menus', 'locations']
    ));
  }

  /**
   * Get all registered menus
   */
  public static function get_all()
  {
    $menus = get_terms('nav_menu', array('hide_empty' => true));

    $locations = get_nav_menu_locations();

    foreach ($menus as $key => $menu) {
      $menu->items = self::get_items($menu->term_id);

      $menu->location = null;
      foreach ($locations as $location => $location_menu) {
        if ($location_menu === $menu->term_id) {
          $menu->location = $location;
          continue;
        }
      }

      // check if ACF is installed
      if (class_exists('acf')) {
        $fields = get_fields($menu);
        if (!empty($fields)) {
          foreach ($fields as $field_key => $item) {
            // add all acf custom fields
            $menus[$key]->$field_key = $item;
          }
        }
      }
    }

    return $menus;
  }

  /**
   * Get all locations
   */
  public static function locations()
  {
    $nav_menu_locations = get_nav_menu_locations();
    $locations = new stdClass;
    foreach ($nav_menu_locations as $location_slug => $menu_id) {
      if (get_term($location_slug) !== null) {
        $locations->{$location_slug} = get_term($location_slug);
      } else {
        $locations->{$location_slug} = new stdClass;
      }
      $locations->{$location_slug}->slug = $location_slug;
      $locations->{$location_slug}->menu = get_term($menu_id);
    }

    return $locations;
  }

  /**
   * Get menu data from menu ID/slug
   */
  public static function menu_data($data)
  {
    if (has_nav_menu($data['id'])) {
      $menu = self::location_data($data);
    } else if (is_nav_menu($data['id'])) {
      if (is_int($data['id'])) {
        $id = $data['id'];
      } else {
        $id = wp_get_nav_menu_object($data['id']);
      }
      $menu = get_term($id);
      $menu->items = self::get_items($id);
    } else {
      return new WP_Error('not_found', 'No menu has been found with this id or slug: `' . $data['id'] . '`. Please ensure you passed an existing menu ID, menu slug, location ID or location slug.', array('status' => 404));
    }

    // check if ACF is installed
    if (class_exists('acf')) {
      $fields = get_fields( $menu );
      if ( ! empty( $fields ) ) {
        foreach ( $fields as $field_key => $item ) {
          // add all acf custom fields
          $menu->$field_key = $item;
        }
      }
    }

    return $menu;
  }

  /**
   * Get menu data from location ID/slug
   */
  public static function location_data($data)
  {
    // Create default empty object
    $menu = new stdClass;

    if (($locations = get_nav_menu_locations()) && isset($locations[$data['id']])) {
      // Replace default empty object with the location object
      $menu = get_term($locations[$data['id']]);
      $menu->items = self::get_items($locations[$data['id']]);
    } else {
      return new WP_Error('not_found', 'No location has been found with this id or slug: `' . $data['id'] . '`. Please ensure you passed an existing location ID or location slug.', array('status' => 404));
    }

    // check if ACF is installed
    if (class_exists('acf')) {
      $fields = get_fields($menu);
      if (!empty($fields)) {
        foreach ($fields as $field_key => $item) {
          // add all acf custom fields
          $menu->$field_key = $item;
        }
      }
    }

    return $menu;
  }

  /**
   * Check if menu item is child of one of menu passed as reference
   *
   * @param $parents Potential parent menu
   * @param $child Menu item to check
   *
   * @return bool True if the parent is found, false otherwise
   */
  private static function dna_test(&$parents, $child)
  {
    foreach ($parents as $key => $item) {
      if ($child->menu_item_parent == $item->ID) {
        if (!$item->child_items) {
          $item->child_items = [];
        }
        array_push($item->child_items, $child);
        return true;
      }

      if($item->child_items) {
        if(self::dna_test($item->child_items, $child)) {
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Retrieve items for specific menu
   */
  private static function get_items($id)
  {
    $menu_items = wp_get_nav_menu_items($id);

    foreach ($menu_items as $menu_item) {
      $item_path = parse_url($menu_item->url, PHP_URL_PATH);
      $menu_item->url = $item_path;
    }

    // check if ACF is installed
    if (class_exists('acf')) {
      foreach ($menu_items as $menu_key => $menu_item) {
        $fields = get_fields($menu_item->ID);
        if (!empty($fields)) {
          foreach ($fields as $field_key => $item) {
            // add all acf custom fields
            $menu_items[$menu_key]->$field_key = $item;
          }
        }
      }
    }

    // wordpress does not group child menu items with parent menu items
    $child_items = [];
    // pull all child menu items into separate object
    foreach ($menu_items as $key => $item) {
      if ($item->menu_item_parent) {
        array_push($child_items, $item);
        unset($menu_items[ $key ]);
      }
    }

    // push child items into their parent item in the original object
    do {
      foreach($child_items as $key => $child_item) {
        if(self::dna_test($menu_items, $child_item)) {
          unset($child_items[$key]);
        }
      }
    } while(count($child_items));

    return array_values($menu_items);
  }
}

Accudio_Headless_Menus::init();
