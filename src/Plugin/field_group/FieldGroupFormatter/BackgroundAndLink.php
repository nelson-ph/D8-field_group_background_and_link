<?php
/**
 * Created by PhpStorm.
 * Filename: BackgroundAndLink.php
 * Descr: Some description
 * User: nelsonpireshassanali
 * Date: 19/12/2018
 * Time: 10:52
 */

namespace Drupal\field_group_background_and_link\Plugin\field_group\FieldGroupFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\field_group\FieldGroupFormatterBase;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\Entity\Media;

/**
 * Plugin implementation of the 'background image' formatter.
 *
 * @FieldGroupFormatter(
 *   id = "background_and_link",
 *   label = @Translation("Background Image, Color and Link"),
 *   description = @Translation("Field group as a background image, background
 *   color or/and link."), supported_contexts = {
 *     "view",
 *   }
 * )
 */
class BackgroundAndLink extends FieldGroupFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function preRender(&$element, $renderingObject) {

    $attributes = new Attribute();

    // Add the HTML ID.
    if ($id = $this->getSetting('id')) {
      $attributes['id'] = Html::getId($id);
    }

    // Add the HTML classes.
    $attributes['class'] = $this->getClasses();

    // Render the element as a HTML div and add the attributes.
    $element['#type'] = 'container_tag';
    $element['#tag']  = 'div';

    // Add the image as a background.
    $image      = $this->getSetting('image');
    $imageStyle = $this->getSetting('image_style');
    $color      = $this->getSetting('color');
    $link       = $this->getSetting('link');
    if ($style = $this->generateStyleAttribute($renderingObject, $image, $imageStyle, $color)) {
      $attributes['style'] = $style;
    }

    if ($link && ($linkValue = $this->linkValue($renderingObject, $link))) {
      $element['#tag']    = 'a';
      $attributes['href'] = Url::fromUri($linkValue['uri'])->toString();
      if (!empty($linkValue['options']['attributes']) && is_array($linkValue['options']['attributes'])) {
        foreach ($linkValue['options']['attributes'] as $key => $value) {
          $attributes[$key] = $value;
        }
      }
    }
    elseif ($this->getSetting('link_to_entity')) {
      $element['#tag']    = 'a';
      $attributes['href'] = $this->getEntityUrl($renderingObject)->toString();
    }
    elseif ($this->getSetting('link_to_file')) {
      $file = $this->mediaFile($renderingObject, $this->getSetting('link_to_file'));
      if(!empty($file)){
        $element['#tag']    = 'a';
        $attributes['href'] = file_create_url($file->getFileUri());

        if(!empty($this->getSetting('link_target'))){
          $attributes['target'] = $this->getSetting('link_target');
        }
      }
    }

    if (empty($style)) {
      if ($this->getSetting('hide_if_missing_image') || $this->getSetting('hide_if_missing_color')) {
        hide($element);
      }
    }
    if (empty($linkValue)) {
      if ($this->getSetting('hide_if_missing_link')) {
        hide($element);
      }
    }

    $element['#attributes'] = $attributes;
  }

  /**
   * Gets all HTML classes, cleaned for displaying.
   *
   * @return array
   *   Classes.
   */
  protected function getClasses() {
    $classes   = parent::getClasses();
    $classes[] = 'field-group-background-and-link';
    $classes   = array_map([
      '\Drupal\Component\Utility\Html',
      'getClass',
    ], $classes);

    return $classes;
  }

  /**
   * Generates the background image style attribute.
   *
   * @param object $renderingObject
   *   Rendering Object.
   * @param string $image
   *   Image.
   * @param string $imageStyle
   *   Image Style.
   * @param string $color
   *   Color.
   *
   * @return string
   *   Background Image style inline with absolute url.
   */
  protected function generateStyleAttribute($renderingObject, $image, $imageStyle, $color) {
    $style = [];

    $validImage      = array_key_exists($image, $this->imageFields());
    $validImageStyle = ($imageStyle === '') || array_key_exists($imageStyle, image_style_options(FALSE));
    $validColor      = array_key_exists($color, $this->colorFields());

    if ($validImage && $validImageStyle) {
      if ($url = $this->imageUrl($renderingObject, $image, $imageStyle)) {
        $style[] = strtr('background-image: url(\'@url\');', ['@url' => $url]);
      }
    }

    if ($validColor) {
      if ($colorCode = $this->colorCode($renderingObject, $color)) {
        $style[] = strtr('background-color: @colorCode;', ['@colorCode' => $colorCode]);
      }
    }

    return implode(' ', $style);
  }

  /**
   * Get all image fields for the current entity and bundle.
   *
   * @return array
   *   Image field key value pair.
   */
  protected function imageFields() {

    $entityFieldManager = \Drupal::service('entity_field.manager');
    $fields             = $entityFieldManager->getFieldDefinitions($this->group->entity_type, $this->group->bundle);

    $imageFields = [];
    foreach ($fields as $field) {
      if ($field->getType() === 'image' || ($field->getType() === 'entity_reference' && $field->getSetting('target_type') == 'media')) {
        $imageFields[$field->get('field_name')] = $field->label();
      }
    }

    return $imageFields;
  }

  /**
   * Get all color fields for the current entity and bundle.
   *
   * @return array
   *   Color field key value pair.
   */
  protected function colorFields() {

    $entityFieldManager = \Drupal::service('entity_field.manager');
    $fields             = $entityFieldManager->getFieldDefinitions($this->group->entity_type, $this->group->bundle);

    $colorFields = [];
    foreach ($fields as $field) {
      if ($field->getType() === 'color_field_type') {
        $colorFields[$field->get('field_name')] = $field->label();
      }
    }

    return $colorFields;
  }

  /**
   * Returns an image URL to be used in the Field Group.
   *
   * @param object $renderingObject
   *   The object being rendered.
   * @param string $field
   *   Image field name.
   * @param string $imageStyle
   *   Image style name.
   *
   * @return string
   *   Image URL.
   */
  protected function imageUrl($renderingObject, $field, $imageStyle) {
    $imageUrl = '';

    if (empty(($file = $this->mediaFile($renderingObject, $field)))) {
      return $imageUrl;
    }

    // When no image style is selected, use the original image.
    if (empty($imageStyle)) {
      $imageUrl = file_create_url($file->getFileUri());
    }
    else {
      $imageUrl = ImageStyle::load($imageStyle)->buildUrl($file->getFileUri());
    }

    return file_url_transform_relative($imageUrl);
  }

  /**
   * @param $renderingObject
   * @param $field
   * @param null $imageStyle
   *
   * @return bool|\Drupal\Core\Entity\EntityInterface|\Drupal\file\Entity\File|null
   */
  protected function mediaFile($renderingObject, $field) {
    $file = FALSE;
    /* @var EntityInterface $entity */
    if (!($entity = $renderingObject['#' . $this->group->entity_type])) {
      return $file;
    }

    if ($imageFieldValue = $renderingObject['#' . $this->group->entity_type]->get($field)
      ->getValue()) {

      // Fid for image or entity_id.
      if (!empty($imageFieldValue[0]['target_id'])) {
        $entity_id = $imageFieldValue[0]['target_id'];

        $fieldDefinition = $entity->getFieldDefinition($field);
        // Get the media or file URI.
        if (
          $fieldDefinition->getType() == 'entity_reference' &&
          $fieldDefinition->getSetting('target_type') == 'media'
        ) {

          // Load media.
          $entity_media = Media::load($entity_id);

          // Loop over entity fields.
          foreach ($entity_media->getFields() as $field_name => $field) {
            if (
              $field->getFieldDefinition()->getType() === 'image' &&
              $field->getFieldDefinition()->getName() !== 'thumbnail'
            ) {
              $file = $entity_media->{$field_name}->entity;
            }
          }
        }
        else {
          $file = File::load($entity_id);
        }
      }
    }

    return $file;
  }

  /**
   * Returns an color code to be used in the Field Group.
   *
   * @param object $renderingObject
   *   The object being rendered.
   * @param string $field
   *   Color field name.
   *
   * @return string
   *   Image URL.
   */
  protected function colorCode($renderingObject, $field) {
    $colorCode = '';

    /* @var EntityInterface $entity */
    if (!($entity = $renderingObject['#' . $this->group->entity_type])) {
      return $colorCode;
    }

    if ($colorFieldValue = $renderingObject['#' . $this->group->entity_type]->get($field)
      ->getValue()) {
      if (!empty($colorFieldValue[0]['color'])) {
        $colorCode = $colorFieldValue[0]['color'];
      }
    }

    return $colorCode;
  }

  /**
   * Returns an link value to be used in the Field Group.
   *
   * @param object $renderingObject
   *   The object being rendered.
   * @param string $field
   *   Color field name.
   *
   * @return string
   *   Image URL.
   */
  protected function linkValue($renderingObject, $field) {
    $linkValue = FALSE;

    /* @var EntityInterface $entity */
    if (!($entity = $renderingObject['#' . $this->group->entity_type])) {
      return $linkValue;
    }

    if ($linkFieldValue = $renderingObject['#' . $this->group->entity_type]->get($field)
      ->getValue()) {
      $linkValue = reset($linkFieldValue);
    }

    return $linkValue;
  }

  /**
   * @param $renderingObject
   *
   * @return bool|\Drupal\Core\Url
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  protected function getEntityUrl($renderingObject) {
    $url = FALSE;

    /* @var \Drupal\Core\Entity\EntityInterface $entity */
    if (!($entity = $renderingObject['#' . $this->group->entity_type])) {
      return $url;
    }

    return $entity->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm() {
    $form = parent::settingsForm();

    $form['label']['#access'] = FALSE;

    if ($imageFields = $this->imageFields()) {
      $form['image']             = [
        '#title'         => $this->t('Image'),
        '#type'          => 'select',
        '#options'       => [
          '' => $this->t('- Select -'),
        ],
        '#default_value' => $this->getSetting('image'),
        '#weight'        => 1,
      ];
      $form['image']['#options'] += $imageFields;

      $form['image_style']             = [
        '#title'         => $this->t('Image style'),
        '#type'          => 'select',
        '#options'       => [
          '' => $this->t('- Select -'),
        ],
        '#default_value' => $this->getSetting('image_style'),
        '#weight'        => 2,
      ];
      $form['image_style']['#options'] += image_style_options(FALSE);

      $form['hide_if_missing_image'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Hide if missing image'),
        '#description'   => $this->t('Do not render the field group if the image is missing from the selected field.'),
        '#default_value' => $this->getSetting('hide_if_missing_image'),
        '#weight'        => 3,
      ];
    }

    if ($colorFields = $this->colorFields()) {
      $form['color']             = [
        '#title'         => $this->t('Color'),
        '#type'          => 'select',
        '#options'       => [
          '' => $this->t('- Select -'),
        ],
        '#default_value' => $this->getSetting('color'),
        '#weight'        => 1,
      ];
      $form['color']['#options'] += $colorFields;

      $form['hide_if_missing_color'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Hide if missing color'),
        '#description'   => $this->t('Do not render the field group if the color is missing from the selected field.'),
        '#default_value' => $this->getSetting('hide_if_missing_color'),
        '#weight'        => 3,
      ];
    }

    if ($linkFields = $this->linkFields()) {
      $form['link']             = [
        '#title'         => $this->t('Link'),
        '#type'          => 'select',
        '#options'       => [
          '' => $this->t('- Select -'),
        ],
        '#default_value' => $this->getSetting('link'),
        '#weight'        => 1,
      ];
      $form['link']['#options'] += $linkFields;

      $form['hide_if_missing_link'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Hide if missing link'),
        '#description'   => $this->t('Do not render the field group if the link is missing from the selected field.'),
        '#default_value' => $this->getSetting('hide_if_missing_link'),
        '#weight'        => 3,
      ];
    }
    else {
      $form['link_to_entity'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Link to the entity'),
        '#default_value' => $this->getSetting('link_to_entity'),
      ];

      if ($fileFields = $this->fileFields()) {
        $form['link_to_file']             = [
          '#title'         => $this->t('Link to file'),
          '#type'          => 'select',
          '#options'       => [
            '' => $this->t('- Select -'),
          ],
          '#default_value' => $this->getSetting('link_to_file'),
          '#weight'        => 1,
        ];
        $form['link_to_file']['#options'] += $fileFields;
      }

      $form['link_target'] = [
        '#type'    => 'select',
        '#title'   => $this->t('Link target'),
        '#options' => [
          ''       => $this->t('- Select -'),
          '_blank' => '_blank',
          '_self'  => '_self',
        ],
      ];

    }

    return $form;
  }

  /**
   * Get all link fields for the current entity and bundle.
   *
   * @return array
   *   Link field key value pair.
   */
  protected function linkFields() {

    $entityFieldManager = \Drupal::service('entity_field.manager');
    $fields             = $entityFieldManager->getFieldDefinitions($this->group->entity_type, $this->group->bundle);

    $linkFields = [];
    foreach ($fields as $field) {
      if ($field->getType() === 'link') {
        $linkFields[$field->get('field_name')] = $field->label();
      }
    }

    return $linkFields;
  }

  protected function fileFields() {
    $entityFieldManager = \Drupal::service('entity_field.manager');
    $fields             = $entityFieldManager->getFieldDefinitions($this->group->entity_type, $this->group->bundle);

    $targetFields = [];
    foreach ($fields as $field) {
      if ($field->getType() === 'file' || ($field->getType() === 'entity_reference' && $field->getSetting('target_type') == 'media')) {
        $targetFields[$field->get('field_name')] = $field->label();
      }
    }

    return $targetFields;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    if ($image = $this->getSetting('image')) {
      $imageFields = $this->imageFields();
      $summary[]   = $this->t('Image field: @image', ['@image' => $imageFields[$image]]);
    }
    if ($imageStyle = $this->getSetting('image_style')) {
      $summary[] = $this->t('Image style: @style', ['@style' => $imageStyle]);
    }
    if ($color = $this->getSetting('color')) {
      $colorFields = $this->colorFields();
      $summary[]   = $this->t('Color field: @image', ['@image' => $colorFields[$color]]);
    }
    if ($link = $this->getSetting('link')) {
      $linkFields = $this->linkFields();
      $summary[]  = $this->t('Link field: @image', ['@image' => $linkFields[$link]]);
    }
    if ($this->getSetting('link_to_entity')) {
      $summary[] = $this->t('Linked to the entity');
    }
    if ($file = $this->getSetting('link_to_file')) {
      $fileFields = $this->fileFields();
      $summary[]  = $this->t('Linked to the file : @field', ['@field' => $fileFields[$file]]);
    }
    if ($this->getSetting('link_target')) {
      $summary[] = $this->t('Link target : @target', ['@target' => $this->getSetting('link_target')]);
    }

    return $summary;
  }

  /**
   * @param $renderingObject
   *
   * @return bool|\Drupal\Core\Url
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  protected function getFileUrl($renderingObject) {
    $url = FALSE;

    /* @var \Drupal\Core\Entity\EntityInterface $entity */
    if (!($entity = $renderingObject['#' . $this->group->entity_type])) {
      return $url;
    }


    return $entity->toUrl();
  }

}
