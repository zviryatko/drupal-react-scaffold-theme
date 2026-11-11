<?php

namespace Drupal\react_scaffold_builder_ai\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The endpoint of the assistant panel of the page builder.
 */
class AssistantController extends ControllerBase {

  /**
   * What the editor measured of the theme, as short plain values: it goes
   * into the instructions of the model, so nothing long or structured.
   */
  protected function look(array $look): array {
    $clean = static fn (mixed $value): string => is_scalar($value) ? mb_substr(preg_replace('/[^\w\s#%.,()\/\'"-]/u', '', (string) $value), 0, 120) : '';
    $result = [];
    foreach (['background', 'color', 'font', 'fontSize', 'headingFont', 'linkColor', 'width'] as $key) {
      $result[$key] = $clean($look[$key] ?? '');
    }
    foreach (['variables', 'viewports'] as $key) {
      foreach (array_slice(is_array($look[$key] ?? NULL) ? $look[$key] : [], 0, 60, TRUE) as $name => $value) {
        $result[$key][$clean($name)] = $clean($value);
      }
    }
    return $result;
  }

  /**
   * One turn of the conversation.
   *
   * Request: {"messages": [{"role": "user"|"assistant", "content": "..."}],
   * "data": the layout as it is in the editor, "image": optionally a
   * screenshot of the canvas}. Answer: {"message": what the
   * assistant says, "data": the proposed layout, or null when it only talks}.
   * Nothing is saved: the editor shows the proposal as an unsaved change.
   */
  public function chat(NodeInterface $node, Request $request): JsonResponse {
    $input = Json::decode($request->getContent()) ?: [];
    $messages = [];
    foreach (is_array($input['messages'] ?? NULL) ? $input['messages'] : [] as $message) {
      if (is_array($message) && in_array($message['role'] ?? '', ['user', 'assistant'], TRUE) && is_string($message['content'] ?? NULL) && trim($message['content']) !== '') {
        $messages[] = ['role' => $message['role'], 'content' => mb_substr($message['content'], 0, 8000)];
      }
    }
    if (!$messages || end($messages)['role'] !== 'user') {
      return new JsonResponse(['errors' => ['Nothing was asked.']], 400);
    }
    $langcode = (string) ($input['langcode'] ?? '');
    if ($node->hasTranslation($langcode)) {
      $node = $node->getTranslation($langcode);
    }
    /** @var \Drupal\react_scaffold_builder_ai\PageAssistant $assistant */
    $assistant = \Drupal::service('react_scaffold_builder_ai.assistant');
    try {
      // A screenshot of the canvas: a JPEG data URL, at most a few megabytes.
      $image = NULL;
      if (is_string($input['image'] ?? NULL) && strlen($input['image']) < 6000000 && preg_match('#^data:image/jpeg;base64,(.+)$#', $input['image'], $match)) {
        $image = base64_decode($match[1], TRUE) ?: NULL;
      }
      return new JsonResponse($assistant->respond($node, array_slice($messages, -12), is_array($input['data'] ?? NULL) ? $input['data'] : [], $image, is_array($input['look'] ?? NULL) ? $this->look($input['look']) : []));
    }
    catch (\Throwable $e) {
      $this->getLogger('react_scaffold_builder_ai')->error('The assistant failed: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['errors' => ['The assistant could not answer: ' . $e->getMessage()]], 502);
    }
  }

}
