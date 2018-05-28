<?php

namespace Drupal\rest_password\Plugin\rest\resource;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Psr\Log\LoggerInterface;
use Drupal\user\UserStorageInterface;

/**
 * Provides a resource to reset Drupal password for user.
 *
 * @RestResource(
 *   id = "lost_password_reset",
 *   label = @Translation("Reset Lost password Via Temp password"),
 *   uri_paths = {
 *     "canonical" = "/user/lost-password-reset",
 *     "https://www.drupal.org/link-relations/create" = "/user/lost-password-reset"
 *   }
 * )
 */
class ResetPasswordFromTempRestResource extends ResourceBase {

  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The user storage.
   *
   * @var \Drupal\user\UserStorageInterface
   */
  protected $userStorage;

  /**
   * Constructs a new GetPasswordRestResourse object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A current user instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user,
    UserStorageInterface $user_storage) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->currentUser = $current_user;
    $this->userStorage = $user_storage;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest_password'),
      $container->get('current_user'),
      $container->get('entity.manager')->getStorage('user')
    );
  }

  /**
   * Responds Post {"name":"username", "temp_pass":"TEMPPASS", "new_pass": "NEWPASS"}
   *
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function post(array $data) {
    $code = 400;
    if (!empty($data['name']) && !empty($data['temp_pass']) && !empty($data['new_pass'])) {
      $name = $data['name'];
      $temp_pass = $data['temp_pass'];
      $new_pass = $data['new_pass'];

      // Try to load by email.
      $users = $this->userStorage->loadByProperties(['name' => $name]);
      if (!empty($users)) {
        $account = reset($users);
        if ($account && $account->id()) {
          // Blocked accounts cannot request a new password.
          if (!$account->isActive()) {
            $responce = t('This account is blocked or has not been activated yet.');
          }
          else {
            // CHECK the temp password.
            $uid = $account->id();
            $service = \Drupal::service('tempstore.shared');
            $collection = 'rest_password';
            $tempstore = $service->get($collection, $uid);
            $temp_pass_from_storage = $tempstore->getIfOwner('temp_pass');
            if (!empty($temp_pass_from_storage)) {
              if ($temp_pass_from_storage === $temp_pass) {
                // Cool.... lets change this password.
                $account->setPassword($new_pass);
                $account->save();
                $code = 200;
                $responce = ['message' => 'Your New Password has been saved please log in.'];
                // delete temp password.
                $tempstore->deleteIfOwner('temp_pass');
              }
            }
            else {
              $responce = ['message' => 'No valid temp password request.'];
            }
          }
        }
      }
      else {
        $responce = ['message' => 'This User was not found or invalid'];
      }
    }
    else {
      $responce = ['message' => 'name, new_pass, and  temp_pass fields are required'];
    }

    return new ResourceResponse($responce, $code);
  }

}
