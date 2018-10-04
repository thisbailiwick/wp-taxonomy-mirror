<?php

 namespace ThisBailiwick\TaxonomyMirror;
 /*
 Plugin Name: Taxonomy Mirror Sync
 */
 const PLUGIN_PREFIX = 'tx_mirror_';
 const OPTION_NAME = 'tb_mirrored_sub_category_ids';
 const TAXONOMY_TO_MIRROR_NAME = 'spot-categories';
 const TAXONOMY_TO_MIRROR_TO_NAME = 'talent-categories';


 register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate');
 register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\deactivate');

 add_action('edit_term', __NAMESPACE__ . '\\sync_categories_edit', 10, 3);
 add_action('created_term', __NAMESPACE__ . '\\sync_categories_create', 10, 3);
 add_action('delete_term', __NAMESPACE__ . '\\sync_categories_delete', 10, 5);

 /**
	*
	*/
 function deactivate() {
	// delete sub term mirror option
	delete_option(OPTION_NAME);
 }

 /**
	* Does initial mirroring of all terms from TAXONOMY_TO_MIRROR_NAME to TAXONOMY_TO_MIRROR_TO_NAME.
	*/
 function activate() {
	// get the saved sub term mirror values
	$mirrored_sub_category_ids = get_option(OPTION_NAME);

	if ($mirrored_sub_category_ids === false) {
	 // we need to add the sub term mirror to the db
	 // create values
	 // get the spot category terms
	 $taxonomy_to_mirror_terms = get_taxonomy_terms(TAXONOMY_TO_MIRROR_NAME);
	 $new_mirrored_sub_category_ids = array();
	 foreach ($taxonomy_to_mirror_terms as $to_mirror_term) {
		// now we add the sub terms to the top level terms of the taxonomy we are mirroring to
		$taxonomy_to_mirror_to_terms = get_taxonomy_terms(TAXONOMY_TO_MIRROR_TO_NAME);
		foreach ($taxonomy_to_mirror_to_terms as $to_mirror_to_term) {
		 if ($to_mirror_to_term->parent === 0) {
			$new_term_ids = wp_insert_term($to_mirror_term->name, TAXONOMY_TO_MIRROR_TO_NAME, array('parent' => $to_mirror_to_term->term_id, 'slug' => $to_mirror_term->name . '-' . $to_mirror_to_term->name));
			if (!isset($new_term_ids->errors)) {
			 $new_mirrored_sub_category_ids[$to_mirror_term->term_id][] = $new_term_ids['term_id'];
			} else {
			 $new_mirrored_sub_category_ids[$to_mirror_term->term_id][] = $new_term_ids->error_data['term_exists'];
			}
		 }
		}
	 }

	 // todo: why are we saving to the options table?
	 add_option(OPTION_NAME, $new_mirrored_sub_category_ids);
	}
 }


 /**
	* When a term name or slug from the TAXONOMY_TO_MIRROR_NAME is edited this will check all of the TAXONOMY_TO_MIRROR_TO_NAME sub terms to make changes.
	* @param $term_id
	* @param $tt_id
	* @param $taxonomy
	*/
 function sync_categories_edit($term_id, $tt_id, $taxonomy) {
	if ($taxonomy === TAXONOMY_TO_MIRROR_NAME) {
	 $mirrored_sub_term_ids = get_option(OPTION_NAME);
	 // get saving term values
	 $saving_term = $_POST;

	 // if the saving term id has a matching term id in the cached values, then compare the name and slug to see if either has changed
	 $cached_values_for_term = $GLOBALS['wp_object_cache']->cache['terms'][$term_id];
	 if (!empty($cached_values_for_term)) {
		$name_changed = false;
		$slug_changed = false;
		if ($cached_values_for_term->name !== $saving_term['name']) {
		 // name changed
		 $name_changed = true;
		}

		$parent_slug = get_term($cached_values_for_term->parent, TAXONOMY_TO_MIRROR_TO_NAME)->slug;

		// compare the cached value (what amounts to the mirror to taxonomy) trimmed of the postfix-ed parent-category name to the saving terms slug (taxonomy to mirror)
		if (str_replace('-' . $parent_slug, '', $cached_values_for_term->slug) !== $saving_term['slug']) {
		 // slug changed
		 $slug_changed = true;
		}

		// if either has changed then
		// loop through sub term mirror array at the id of the changing term and update within those terms whatever has changed for the spot category
		if ($name_changed === true || $slug_changed === true) {
		 foreach ($mirrored_sub_term_ids[$saving_term['tax_ID']] as $sub_term_id) {
			// lazy blanket set the term name and slug
			if ($name_changed === true) {
			 wp_update_term($sub_term_id, TAXONOMY_TO_MIRROR_TO_NAME, array(
				 'name' => $saving_term['name']
			 ));
			}

			if ($slug_changed === true) {
			 // need to postfix the taxonomy to mirror to parent name to slug
			 $parent_slug = get_term(get_term($sub_term_id, TAXONOMY_TO_MIRROR_TO_NAME)->parent, TAXONOMY_TO_MIRROR_TO_NAME)->slug;
			 wp_update_term($sub_term_id, TAXONOMY_TO_MIRROR_TO_NAME, array(
				 'slug' => $saving_term['slug'] . '-' . $parent_slug
			 ));
			}
		 }
		}
	 }
	}
 }

 /**
	* When a new term is added to TAXONOMY_TO_MIRROR_NAME this will mirror the term to all TAXONOMY_TO_MIRROR_TO_NAME top lever terms as sub terms
	* @param $term_id
	* @param $tt_id
	* @param $taxonomy
	*/
 function sync_categories_create($term_id, $tt_id, $taxonomy) {
	if ($taxonomy === TAXONOMY_TO_MIRROR_NAME) {
	 // add new term as sub categories to all
	 $saving_term_name = $_POST['tag-name'];
	 $saving_term_slug = get_term_by('name', $_POST['tag-name'], 'spot-categories')->slug;
	 $taxonomy_to_mirror_to_terms = get_taxonomy_terms(TAXONOMY_TO_MIRROR_TO_NAME);
	 $mirror_term_values_to_update = array();
	 foreach ($taxonomy_to_mirror_to_terms as $term) {
		if ($term->parent === 0) {
		 // add the new term to the parent term
		 $new_term_ids = wp_insert_term($saving_term_name, TAXONOMY_TO_MIRROR_TO_NAME, array('parent' => $term->term_id, 'slug' => $saving_term_slug . '-' . $term->name));
		 $mirror_term_values_to_update[] = $new_term_ids['term_id'];
		}
	 }

	 // get current mirror term values
	 $mirrored_sub_term_ids = get_option(OPTION_NAME);
	 // add the new term values to them
	 $mirrored_sub_term_ids[$term_id] = $mirror_term_values_to_update;
	 //save the new value
	 update_option(OPTION_NAME, $mirrored_sub_term_ids);
	}
 }

 /**
	* When a term from TAXONOMY_TO_MIRROR_NAME is deleted this will loop through all TAXONOMY_TO_MIRROR_TO_NAME terms and delete the relevant sub terms
	* todo: does this delete the term associations to the post object?
	* @param $term_id
	* @param $tt_id
	* @param $taxonomy
	* @param $deleted_term
	* @param $object_ids
	*/
 function sync_categories_delete($term_id, $tt_id, $taxonomy, $deleted_term, $object_ids) {
	if ($taxonomy === TAXONOMY_TO_MIRROR_NAME) {
	 // get current mirror term values
	 $mirrored_sub_term_ids = get_option(OPTION_NAME);
	 // remove all terms with ids which are in saved sub term mirror where the index is matching the id of the deleted term
	 // loop through at the index which matches the id of the deleted term
	 foreach ($mirrored_sub_term_ids[$term_id] as $sub_term_id) {
		//delete em' from the actual sub terms
		wp_delete_term($sub_term_id, TAXONOMY_TO_MIRROR_TO_NAME);
	 }
	 // delete em' from the sub term mirror array
	 unset($mirrored_sub_term_ids[$term_id]);
	 //save the new value to options in db
	 update_option(OPTION_NAME, $mirrored_sub_term_ids);
	}

 }

 /**
	* @param $taxonomy_name
	* @param $return_ids - default 'all', other options can be found: http://developer.wordpress.org/reference/classes/wp_term_query/__construct/
	* @return array|int|\WP_Error
	*/
 function get_taxonomy_terms($taxonomy_name, $return_ids = 'all') {

	$terms_args = array(
		'taxonomy' => $taxonomy_name,
		'fields' => $return_ids,
		'hide_empty' => false
	);
	// get the talent category terms
	$terms = get_terms($terms_args);
	return $terms;
 }
