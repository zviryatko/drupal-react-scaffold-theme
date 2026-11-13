<?php

declare(strict_types=1);

namespace Drupal\frame_builder_ai_test\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A chat provider that answers from a script instead of asking a model.
 *
 * A test puts the answers in the state (self::ANSWERS, a list of texts): each
 * call takes the next one. The answer "THROW" fails like a provider that is
 * down. Every request is kept in the state (self::REQUESTS) for the test to
 * read: the system prompt, the messages, the model and the tags.
 */
#[AiProvider(
  id: 'frame_builder_ai_scripted',
  label: new TranslatableMarkup('Scripted (tests of Frame Builder AI)'),
)]
class ScriptedProvider extends AiProviderClientBase implements ChatInterface {

  /**
   * State key of the answers still to give.
   */
  public const ANSWERS = 'frame_builder_ai_test.answers';

  /**
   * State key of the requests received.
   */
  public const REQUESTS = 'frame_builder_ai_test.requests';

  /**
   * What is answered when the script has run out.
   */
  public const OUT_OF_SCRIPT = '{"message": "Out of script.", "layout": null}';

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('system.site');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiDefinition(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    return ['scripted' => 'Scripted'];
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['chat'];
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $state = \Drupal::state();
    $state->resetCache();
    $messages = [];
    if ($input instanceof ChatInput) {
      foreach ($input->getMessages() as $message) {
        $messages[] = ['role' => $message->getRole(), 'text' => $message->getText(), 'images' => count($message->getImages())];
      }
    }
    $requests = $state->get(self::REQUESTS, []);
    $requests[] = [
      'system' => $input instanceof ChatInput ? $input->getSystemPrompt() : '',
      'messages' => $messages,
      'model' => $model_id,
      'tags' => $tags,
    ];
    $state->set(self::REQUESTS, $requests);

    $answers = $state->get(self::ANSWERS, []);
    $answer = $answers ? array_shift($answers) : self::OUT_OF_SCRIPT;
    $state->set(self::ANSWERS, $answers);
    if ($answer === 'THROW') {
      throw new \RuntimeException('The scripted provider is down.');
    }
    return new ChatOutput(new ChatMessage('assistant', $answer), ['text' => $answer], []);
  }

}
