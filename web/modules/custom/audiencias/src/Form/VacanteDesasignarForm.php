<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\FuncionarioRepository;
use Drupal\audiencias\Repository\VacanteRepository;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Formulario de confirmación para reversar la asignación de una vacante.
 *
 * Deja el «funcionario_id» de la vacante en NULL («Sin asignar»), liberando al
 * funcionario para que pueda asignarse a otra vacante del mismo cargo.
 */
class VacanteDesasignarForm extends ConfirmFormBase {

  /**
   * Repositorio de vacantes.
   *
   * @var \Drupal\audiencias\Repository\VacanteRepository
   */
  protected VacanteRepository $vacanteRepository;

  /**
   * Repositorio de funcionarios.
   *
   * @var \Drupal\audiencias\Repository\FuncionarioRepository
   */
  protected FuncionarioRepository $funcionarioRepository;

  /**
   * La vacante sobre la que se opera.
   *
   * @var object|null
   */
  protected ?object $vacante = NULL;

  /**
   * El funcionario actualmente asignado a la vacante.
   *
   * @var object|null
   */
  protected ?object $funcionario = NULL;

  /**
   * Construye el formulario.
   *
   * @param \Drupal\audiencias\Repository\VacanteRepository $vacante_repository
   *   Repositorio de vacantes.
   * @param \Drupal\audiencias\Repository\FuncionarioRepository $funcionario_repository
   *   Repositorio de funcionarios.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   Pila de peticiones para resolver el destino de regreso.
   */
  public function __construct(
    VacanteRepository $vacante_repository,
    FuncionarioRepository $funcionario_repository,
    MessengerInterface $messenger,
    RequestStack $request_stack,
  ) {
    $this->vacanteRepository = $vacante_repository;
    $this->funcionarioRepository = $funcionario_repository;
    $this->messenger = $messenger;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.vacante_repository'),
      $container->get('audiencias.funcionario_repository'),
      $container->get('messenger'),
      $container->get('request_stack'),
    );
  }

  /**
   * Resuelve un destino INTERNO seguro a partir del «destination» de la URL.
   *
   * Solo se honran rutas internas (que empiecen por «/»); cualquier URL externa
   * o protocolo-relativa se descarta por seguridad, para que un «destination»
   * manipulado no pueda sacar al usuario del sitio.
   *
   * @return \Drupal\Core\Url|null
   *   La URL interna de destino, o NULL si no hay un destino interno válido.
   */
  protected function destinoInterno(): ?Url {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return NULL;
    }
    $destino = (string) $request->query->get('destination', '');
    // Vacío o externo (incluida la forma protocolo-relativa «//host»): se ignora.
    if ($destino === '' || UrlHelper::isExternal($destino)) {
      return NULL;
    }
    try {
      // «fromUserInput» exige que la ruta empiece por «/»; si no, lanza excepción.
      return Url::fromUserInput($destino);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Devuelve la URL de regreso: el destino interno o, si no hay, el respaldo.
   *
   * El respaldo es la pantalla de asignación del CARGO de la vacante (no el
   * listado genérico), para volver justo al contexto desde el que se reversa.
   *
   * @return \Drupal\Core\Url
   *   URL de regreso.
   */
  protected function urlRegreso(): Url {
    $interno = $this->destinoInterno();
    if ($interno !== NULL) {
      return $interno;
    }
    return Url::fromRoute('audiencias.asignacion', [
      'cargo_id' => (int) $this->vacante->cargo_id,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_vacante_desasignar_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $vacante_id
   *   Identificador de la vacante a desasignar.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $vacante_id = NULL): array {
    $this->vacante = $vacante_id !== NULL ? $this->vacanteRepository->load($vacante_id) : NULL;
    if ($this->vacante === NULL) {
      throw new NotFoundHttpException('La vacante indicada no existe.');
    }

    // Si la vacante no tiene funcionario asignado, no hay nada que reversar.
    if (empty($this->vacante->funcionario_id)) {
      $this->messenger->addWarning($this->t('La vacante no tiene funcionario asignado.'));
      $form_state->setRedirectUrl($this->urlRegreso());
      return $form;
    }

    // Resolver el funcionario asignado para mostrarlo en la confirmación.
    $this->funcionario = $this->funcionarioRepository->load((int) $this->vacante->funcionario_id);

    $form = parent::buildForm($form, $form_state);

    // Ocupante que el operador está viendo AHORA. Se guarda en un campo OCULTO
    // —no en un «#type => value», que se recalcularía al reconstruir el
    // formulario en el envío y reintroduciría la carrera— para que en el POST
    // viaje el funcionario que realmente se confirmó. Si el valor se manipula,
    // el UPDATE afectará 0 filas y la reversa se rechaza: falla de forma segura.
    $form['funcionario_esperado'] = [
      '#type' => 'hidden',
      '#default_value' => (int) $this->vacante->funcionario_id,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): \Stringable|string {
    $nombre_funcionario = $this->funcionario !== NULL
      ? $this->funcionario->nombre
      : $this->t('(desconocido)');

    return $this->t('¿Desea reversar la asignación de la vacante «@vacante», ocupada por @funcionario?', [
      '@vacante' => $this->vacante->nombre,
      '@funcionario' => $nombre_funcionario,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): \Stringable|string {
    return $this->t('La vacante quedará «Sin asignar» y el funcionario quedará libre para asignarse a otra vacante del mismo cargo. Esta acción se puede deshacer asignando de nuevo.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): \Stringable|string {
    return $this->t('Reversar asignación');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->urlRegreso();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $vacante_id = (int) $this->vacante->vacante_id;
    $esperado = $form_state->getValue('funcionario_esperado');

    // Valor ausente o no numérico: formulario manipulado o petición malformada.
    // Se rechaza; nunca se cae a la reversa sin comprobar el ocupante.
    if (!is_numeric($esperado) || (int) $esperado <= 0) {
      $this->messenger->addError($this->t('No se reversó la vacante «@vacante»: la solicitud llegó incompleta. Actualice la pantalla y vuelva a intentarlo.', [
        '@vacante' => $this->vacante->nombre,
      ]));
      $form_state->setRedirectUrl($this->urlRegreso());
      return;
    }

    if ($this->vacanteRepository->desasignar($vacante_id, (int) $esperado) === 0) {
      // La vacante ya no la ocupa el funcionario que vio el operador: hay que
      // distinguir el reenvío benigno del conflicto real.
      $actual = $this->vacanteRepository->load($vacante_id);
      if ($actual !== NULL && !empty($actual->funcionario_id)) {
        // Conflicto real: ahora la ocupa otro funcionario.
        $ocupante = $this->funcionarioRepository->load((int) $actual->funcionario_id);
        $this->messenger->addError($this->t('No se reversó la vacante «@vacante»: ahora la ocupa @ocupante (cédula @documento). Actualice la pantalla y vuelva a intentarlo.', [
          '@vacante' => $this->vacante->nombre,
          '@ocupante' => $ocupante !== NULL ? $ocupante->nombre : $this->t('(desconocido)'),
          '@documento' => $ocupante !== NULL ? $ocupante->documento : '—',
        ]));
      }
      else {
        // Benigno: ya estaba reversada (reenvío, recarga o doble clic).
        $this->messenger->addWarning($this->t('La vacante «@vacante» ya estaba «Sin asignar»; no se hizo ningún cambio.', [
          '@vacante' => $this->vacante->nombre,
        ]));
      }
      $form_state->setRedirectUrl($this->urlRegreso());
      return;
    }

    $this->messenger->addStatus($this->t('Se reversó la asignación de la vacante «@vacante». Ahora está «Sin asignar».', [
      '@vacante' => $this->vacante->nombre,
    ]));
    // Vuelve al contexto de origen (destino interno) o, si no hay, a la pantalla
    // de asignación del cargo de la vacante.
    $form_state->setRedirectUrl($this->urlRegreso());
  }

}
