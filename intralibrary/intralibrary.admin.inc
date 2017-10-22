<?php

/**
 * @file
 * Provides Admin interface to Intralibrary.
 */

/**
 * Implements hook_admin().
 */
function intralibrary_admin($form, &$form_state) {
  

  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('@save', array('@save' => OSCADDIE_GCS_ADMIN_SUBMIT_BUTTON)),
    '#submit' => array('oscaddie_gcs_admin_submit'),
  );

  return $form;
}


/**
 * Validates the admin form.
 *
 * Tests the credentials the user entered and responds with a message.
 *
 * @param array $form
 *   The form array.
 * @param array $form_state
 *   The form_state array.
 */
function intralibrary_admin_validate(array $form, array &$form_state) {

}

/**
 * Submit handler for admin form.
 *
 * Saves the variables
 * @param array $form
 *   The form array.
 * @param array $form_state
 *   The form_state array.
 */
function intralibrary_admin_submit(array $form, array &$form_state) {
 
}
