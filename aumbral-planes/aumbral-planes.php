<?php
/**
 * Plugin Name: A Umbral · Planes
 * Description: Módulo «planes» de la app interna. Recoge las solicitudes de plan enviadas desde los formularios de la web, calcula las semanas de inicio y lleva el seguimiento hasta que el plan queda cargado en TrainingPeaks.
 * Version: 1.0.0
 * Author: A Umbral
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class AUmbral_Planes {

	const SLUG  = 'planes';
	const TABLA = 'aumbral_planes';
	const CAP   = 'manage_woocommerce';
	const DB    = 1;

	private static $inst;
	public static function i() { return self::$inst ?: ( self::$inst = new self() ); }

	public static function tabla() { global $wpdb; return $wpdb->prefix . self::TABLA; }

	const OPT_SILENCIO = 'aumbral_planes_silencio';
	const OPT_CUENTA   = 'aumbral_planes_silenciados';

	private function __construct() {
		add_filter( 'aumbral_app_modulos', array( $this, 'registrar' ) );
		add_filter( 'pre_wp_mail', array( $this, 'silenciar_correo' ), 20, 2 );
		add_action( 'admin_post_aumbral_planes_accion', array( $this, 'accion' ) );
		// Copia propia de cada envío nuevo: la app no depende de que Elementor conserve sus tablas.
		add_action( 'elementor_pro/forms/new_record', array( $this, 'on_envio' ), 20, 2 );
	}

	/* ───────────── Aviso de formulario por correo ───────────── */

	/**
	 * Con el interruptor activado, el aviso de formulario de Elementor no sale hacia Gmail:
	 * la solicitud ya está en esta app. Solo afecta a ese asunto exacto; cualquier otro
	 * correo del sitio (pedidos, contraseñas, respuestas) sigue su curso.
	 */
	public function silenciar_correo( $corto, $args ) {
		if ( null !== $corto ) return $corto;
		if ( ! get_option( self::OPT_SILENCIO ) ) return $corto;

		$asunto = isset( $args['subject'] ) ? (string) $args['subject'] : '';
		if ( strpos( $asunto, 'Nuevo mensaje desde' ) !== 0 ) return $corto;

		update_option( self::OPT_CUENTA, (int) get_option( self::OPT_CUENTA ) + 1, false );
		update_option( 'aumbral_planes_silencio_ultimo', current_time( 'mysql' ), false );
		return true; // se da por enviado sin enviarlo
	}

	/* ───────────── Instalación ───────────── */

	public static function instalar() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::tabla();
		$c = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE $t (
			submission_id bigint(20) unsigned NOT NULL,
			fecha datetime NOT NULL,
			nombre varchar(120) DEFAULT '',
			apellidos varchar(120) DEFAULT '',
			email varchar(190) DEFAULT '',
			modalidad varchar(60) DEFAULT '',
			viene_de_otro varchar(60) DEFAULT '',
			mensaje text,
			slug varchar(190) DEFAULT '',
			tipo varchar(10) DEFAULT '',
			user_id bigint(20) unsigned DEFAULT 0,
			lleva_inicio tinyint(1) NOT NULL DEFAULT 0,
			estado varchar(20) NOT NULL DEFAULT 'pendiente',
			fecha_inicio date DEFAULT NULL,
			fecha_paso date DEFAULT NULL,
			nota text,
			actor bigint(20) unsigned DEFAULT 0,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (submission_id),
			KEY email (email),
			KEY estado (estado),
			KEY fecha_paso (fecha_paso),
			KEY fecha (fecha)
		) $c;" );
		update_option( 'aumbral_planes_db', self::DB );
	}

	/* ───────────── Lectura de formularios ───────────── */

	/** Traduce un envío de Elementor a nuestros campos, sin depender de los IDs de campo. */
	private function mapear( $vals ) {
		$d = array( 'nombre' => '', 'apellidos' => '', 'email' => '', 'modalidad' => '', 'viene_de_otro' => '', 'mensaje' => '' );
		$sueltos = array();

		foreach ( $vals as $k => $v ) {
			$v = trim( (string) $v );
			$k = (string) $k;
			if ( $k === 'name' )    { $d['nombre'] = $v; continue; }
			if ( $k === 'email' )   { $d['email']  = $v; continue; }
			if ( $k === 'message' ) { $d['mensaje'] = $v; continue; }
			if ( $v === '' ) continue;
			// Campos con ID variable: se reconocen por su contenido.
			if ( preg_match( '/^(vatios|frecuencia)/i', $v ) )            { $d['modalidad'] = $v; continue; }
			if ( preg_match( '/^(no,|s[ií],)/iu', $v ) )                  { $d['viene_de_otro'] = $v; continue; }
			if ( is_email( $v ) && ! $d['email'] )                        { $d['email'] = $v; continue; }
			$sueltos[] = $v;
		}
		if ( ! $d['apellidos'] && $sueltos ) $d['apellidos'] = $sueltos[0];

		$d['nombre']    = $this->capitalizar( $d['nombre'] );
		$d['apellidos'] = $this->capitalizar( $d['apellidos'] );
		$d['email']     = strtolower( $d['email'] );
		return $d;
	}

	private function capitalizar( $s ) {
		$s = trim( preg_replace( '/\s+/u', ' ', (string) $s ) );
		if ( $s === '' ) return '';
		return mb_convert_case( mb_strtolower( $s, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
	}

	private function tipo_de( $slug ) {
		if ( strpos( $slug, 'fw-' ) === 0 )     return 'fw';
		if ( $slug === 'entrenamiento-anual' )  return 'anual';
		return 'marcha';
	}

	private function slug_de( $referer ) {
		$p = trim( (string) wp_parse_url( (string) $referer, PHP_URL_PATH ), '/' );
		$p = explode( '/', $p );
		return sanitize_title( end( $p ) );
	}

	/** Importa a nuestra tabla los envíos de Elementor que aún no estén. */
	public function sincronizar( $desde = null ) {
		global $wpdb;
		$t     = self::tabla();
		$desde = $desde ?: gmdate( 'Y-m-d H:i:s', strtotime( '-180 days' ) );

		$nuevos = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.created_at, s.referer, s.user_id
			 FROM {$wpdb->prefix}e_submissions s
			 LEFT JOIN $t p ON p.submission_id = s.id
			 WHERE p.submission_id IS NULL AND s.created_at >= %s
			 ORDER BY s.id ASC", $desde
		), ARRAY_A );

		$n = 0;
		foreach ( $nuevos as $s ) {
			$vals = array();
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT `key`, value FROM {$wpdb->prefix}e_submissions_values WHERE submission_id = %d", $s['id']
			), ARRAY_A );
			foreach ( $rows as $r ) $vals[ $r['key'] ] = $r['value'];
			if ( ! $vals ) continue;

			$slug = $this->slug_de( $s['referer'] );
			$this->guardar( array_merge( $this->mapear( $vals ), array(
				'submission_id' => (int) $s['id'],
				'fecha'         => $s['created_at'],
				'slug'          => $slug,
				'tipo'          => $this->tipo_de( $slug ),
				'user_id'       => (int) $s['user_id'],
			) ) );
			$n++;
		}
		return $n;
	}

	/** Alta directa desde el hook de Elementor (no espera a la sincronización). */
	public function on_envio( $record, $handler ) {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) return;
		$campos = $record->get( 'fields' );
		if ( ! is_array( $campos ) ) return;

		$vals = array();
		foreach ( $campos as $id => $c ) {
			$vals[ isset( $c['type'] ) && in_array( $c['type'], array( 'email' ), true ) ? 'email' : $id ] = isset( $c['value'] ) ? $c['value'] : '';
		}
		$meta    = $record->get( 'meta' );
		$referer = isset( $meta['page_url']['value'] ) ? $meta['page_url']['value'] : '';
		$slug    = $this->slug_de( $referer );

		// El id de submission de Elementor puede no existir todavía: usamos negativo temporal
		// y la sincronización posterior lo consolida por email + fecha.
		$sid = 0;
		if ( method_exists( $record, 'get_form_settings' ) ) {
			global $wpdb;
			$sid = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}e_submissions" );
		}
		if ( ! $sid ) return;

		$this->guardar( array_merge( $this->mapear( $vals ), array(
			'submission_id' => $sid,
			'fecha'         => current_time( 'mysql' ),
			'slug'          => $slug,
			'tipo'          => $this->tipo_de( $slug ),
			'user_id'       => get_current_user_id(),
		) ) );
	}

	private function guardar( $d ) {
		global $wpdb;
		$t = self::tabla();
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT submission_id FROM $t WHERE submission_id = %d", $d['submission_id'] ) ) ) return;

		$lleva = $this->decide_inicio( $d['email'], $d['viene_de_otro'], $d['tipo'] );
		$f     = $this->fechas( $d['fecha'] );

		$wpdb->insert( $t, array(
			'submission_id' => $d['submission_id'],
			'fecha'         => $d['fecha'],
			'nombre'        => $d['nombre'],
			'apellidos'     => $d['apellidos'],
			'email'         => $d['email'],
			'modalidad'     => $d['modalidad'],
			'viene_de_otro' => $d['viene_de_otro'],
			'mensaje'       => $d['mensaje'],
			'slug'          => $d['slug'],
			'tipo'          => $d['tipo'],
			'user_id'       => $d['user_id'],
			'lleva_inicio'  => $lleva ? 1 : 0,
			'estado'        => 'pendiente',
			'fecha_inicio'  => $lleva ? $f['inicio'] : null,
			'fecha_paso'    => $lleva ? $f['paso'] : null,
			'updated_at'    => current_time( 'mysql' ),
		) );
	}

	/**
	 * ¿Lleva dos semanas de inicio?
	 * Manda la declaración: si en cualquier formulario de esa persona consta «No, este es el
	 * primero», lleva inicio aunque el plan pedido sea un FW (regla 3 del documento).
	 */
	private function decide_inicio( $email, $viene, $tipo ) {
		if ( preg_match( '/^no,/i', (string) $viene ) ) return true;
		if ( preg_match( '/^s[ií],/iu', (string) $viene ) ) return false;

		if ( $email ) {
			global $wpdb;
			$t = self::tabla();
			$previo = $wpdb->get_var( $wpdb->prepare(
				"SELECT viene_de_otro FROM $t WHERE email = %s AND viene_de_otro <> '' ORDER BY fecha DESC LIMIT 1", $email
			) );
			if ( $previo ) return (bool) preg_match( '/^no,/i', $previo );
		}
		return false; // FW sin antecedentes: plan directo
	}

	/** Lunes a domingo: lun-mié arranca esa misma semana; jue-dom, la siguiente. */
	public function fechas( $fecha ) {
		$d = new DateTime( $fecha, wp_timezone() );
		$n = (int) $d->format( 'N' );
		$l = clone $d;
		$l->modify( '-' . ( $n - 1 ) . ' days' );
		if ( $n >= 4 ) $l->modify( '+7 days' );
		$p = clone $l;
		$p->modify( '+14 days' );
		return array( 'inicio' => $l->format( 'Y-m-d' ), 'paso' => $p->format( 'Y-m-d' ) );
	}

	/* ───────────── Consultas ───────────── */

	public function filas( $where = '1=1', $args = array(), $orden = 'fecha DESC', $limite = 0 ) {
		global $wpdb;
		$t = self::tabla();
		$sql = "SELECT * FROM $t WHERE $where ORDER BY $orden";
		if ( $args ) $sql = $wpdb->prepare( $sql, $args );
		if ( $limite ) $sql .= ' LIMIT ' . (int) $limite;
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	public function contadores() {
		global $wpdb;
		$t   = self::tabla();
		$hoy = current_time( 'Y-m-d' );
		return array(
			'pendiente' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE estado='pendiente'" ),
			'inicio'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE estado='inicio'" ),
			'vencen'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE estado='inicio' AND fecha_paso <= %s", $hoy ) ),
			'proximos'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE estado='inicio' AND fecha_paso > %s AND fecha_paso <= %s", $hoy, gmdate( 'Y-m-d', strtotime( $hoy . ' +7 days' ) ) ) ),
		);
	}

	/** Avisos: duplicados, reenvíos repetidos, mensajes con texto. */
	public function avisos( $filas ) {
		$por_email = array();
		foreach ( $filas as $f ) {
			if ( $f['email'] ) $por_email[ $f['email'] ][] = $f;
		}
		$av = array();
		foreach ( $por_email as $mail => $fs ) {
			if ( count( $fs ) < 2 ) continue;
			$abiertos = array_filter( $fs, function ( $x ) { return $x['estado'] === 'pendiente'; } );
			if ( count( $abiertos ) >= 2 ) {
				$av[ $mail ] = count( $fs ) >= 3
					? 'Ha enviado ' . count( $fs ) . ' formularios: puede que no vea su plan cargado. Conviene escribirle.'
					: 'Envío duplicado o corrección: manda el último.';
			}
		}
		return $av;
	}

	/* ───────────── Borrador de respuesta ───────────── */

	/**
	 * Compone una respuesta a partir del comentario del ciclista y de los datos ya calculados.
	 * Nunca se envía sola: se abre en Gmail para revisarla y editarla.
	 * Lo que no consta en la ficha se deja marcado entre corchetes, a propósito.
	 */
	public function borrador( $f ) {
		$msg = (string) $f['mensaje'];
		$fx  = $this->fechas( $f['fecha'] );
		$ini = $f['fecha_inicio'] ?: $fx['inicio'];
		$pas = $f['fecha_paso'] ?: $fx['paso'];
		$dia = function ( $d ) { return wp_date( 'l j \d\e F', strtotime( $d ) ); };

		$t   = array();
		$t[] = 'Hola ' . ( $f['nombre'] ?: '' ) . ',';
		$t[] = '';

		// Estado de la carga
		if ( $f['estado'] === 'hecho' ) {
			$t[] = 'Ya tienes el plan cargado en TrainingPeaks.';
		} elseif ( $f['lleva_inicio'] ) {
			$t[] = ( $f['estado'] === 'inicio' ? 'Ya tienes cargadas' : 'Te dejo cargadas' )
				. ' dos semanas de inicio desde el ' . $dia( $ini )
				. '. Al terminarlas, el ' . $dia( $pas ) . ' arrancas ya con el plan que has pedido.';
		} else {
			$t[] = ( $f['estado'] === 'pendiente' ? 'Te dejo el plan cargado' : 'Tienes el plan cargado' )
				. ' en TrainingPeaks para empezar el ' . $dia( $ini ) . '.';
		}

		// Lo que cuenta en su mensaje
		$b = array();
		if ( preg_match( '/cuestionario/iu', $msg ) ) {
			$b[] = 'El cuestionario lo tienes aquí: [ENLACE DEL CUESTIONARIO]. Rellénalo cuando puedas y ajusto la planificación con lo que me cuentes.';
		}
		if ( preg_match( '/training\s?peaks|trainingpeaks|vincul|sincroniz|no me aparece|no veo|acceso|invitaci/iu', $msg ) ) {
			$b[] = 'Si todavía no tienes la cuenta de TrainingPeaks vinculada conmigo, dímelo y te mando la invitación para que te aparezcan los entrenamientos.';
		}
		if ( $f['modalidad'] && preg_match( '/cambi|equivoqu|rectific|mejor|prefer|prefier|me va mejor/iu', $msg ) ) {
			$b[] = 'Te lo dejo en ' . mb_strtolower( $f['modalidad'] ) . ', como me dices. Sin problema por el cambio.';
		}
		if ( preg_match( '/(empezar|comenzar|arrancar|iniciar|pasar).{0,40}(lunes|semana que viene|pr[oó]xima semana|d[ií]a \d|el \d{1,2})/iu', $msg ) ) {
			$b[] = 'Lo cuadro para la fecha que me pides.';
		}
		if ( preg_match( '/grupeta|entre semana|fin de semana|rodillo|mis d[ií]as|los d[ií]as|por trabajo|natación|natacion|fuerza|gimnasio|salir con/iu', $msg ) ) {
			$b[] = 'Tomo nota de tus días y de cómo te organizas la semana para cuadrar las sesiones.';
		}
		if ( preg_match( '/lesi[oó]n|opera|molestia|dolor|enferm|reposo|baja/iu', $msg ) ) {
			$b[] = 'Con lo que me cuentas, las primeras semanas irán progresivas. Si algo te molesta, para y me dices.';
		}
		if ( preg_match( '/vacacion|par[oó]n|parad|vuelvo|volver|retom|sin entrenar|inactiv/iu', $msg ) ) {
			$b[] = 'Después del parón, la vuelta va escalonada a propósito: no te asustes si las primeras sesiones te saben a poco.';
		}
		if ( preg_match( '/marcha|carrera|competi|objetivo|prueba|gran fondo|xcm|popular/iu', $msg )
			&& ! preg_match( '/termin[eé]|acab[eé]|finalic|ya hice|complet[eé]|el pasado/iu', $msg ) ) {
			$b[] = 'Tomo nota del objetivo para orientar el trabajo hacia esa fecha.';
		}
		if ( preg_match( '/\b(soy|somos|es mi)\s+(nuev[oa]|primer)|nuev[oa]\s+(en|por aqu)|primera vez|reci[eé]n/iu', $msg ) ) {
			$b[] = 'Cualquier cosa que no encuentres al principio, escríbeme y te lo explico: al final es cuestión de un par de semanas cogerle el aire.';
		}

		if ( $b ) {
			$t[] = '';
			$t[] = implode( "\n\n", array_slice( $b, 0, 3 ) );
		}

		// Pregunta abierta sin encajar en nada de lo anterior
		if ( ! $b && strpos( $msg, '?' ) !== false ) {
			$t[] = '';
			$t[] = 'Sobre lo que me preguntas: [responder].';
		}

		$t[] = '';
		$t[] = 'Cualquier duda, me dices.';
		$t[] = '';
		$t[] = 'Un saludo,';
		$t[] = 'Eduardo';

		return implode( "\n", $t );
	}

	/* ───────────── Registro en la app ───────────── */

	public function registrar( $m ) {
		$m[ self::SLUG ] = array(
			'nav'    => 'Planes',
			'titulo' => 'Planes',
			'orden'  => 20,
			'cap'    => self::CAP,
			'badge'  => array( $this, 'badge' ),
			'render' => array( $this, 'cuerpo' ),
		);
		return $m;
	}

	public function badge() {
		$c = $this->contadores();
		return $c['pendiente'] + $c['vencen'];
	}

	/* ───────────── Acciones ───────────── */

	public function accion() {
		if ( ! current_user_can( self::CAP ) ) wp_die( 'Sin permiso' );
		check_admin_referer( 'aumbral_planes' );

		global $wpdb;
		$t   = self::tabla();
		$id  = absint( $_POST['id'] ?? 0 );
		$que = sanitize_key( $_POST['que'] ?? '' );
		$fila = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE submission_id = %d", $id ), ARRAY_A ) : null;

		if ( $que === 'correo' ) {
			update_option( self::OPT_SILENCIO, get_option( self::OPT_SILENCIO ) ? 0 : 1, false );
			wp_safe_redirect( wp_get_referer() ?: aumbral_app_url( self::SLUG ) );
			exit;
		}

		if ( $fila ) {
			$up = array( 'actor' => get_current_user_id(), 'updated_at' => current_time( 'mysql' ) );

			if ( $que === 'cargado_inicio' || $que === 'cargado_directo' ) {
				$up['lleva_inicio'] = $que === 'cargado_inicio' ? 1 : 0;
				$fila['lleva_inicio'] = $up['lleva_inicio'];
				$que = 'cargado';
			}

			if ( $que === 'cargado' ) {
				if ( $fila['lleva_inicio'] ) {
					$up['estado'] = 'inicio';
					if ( empty( $fila['fecha_paso'] ) ) {
						$f = $this->fechas( $fila['fecha'] );
						$up['fecha_inicio'] = $f['inicio'];
						$up['fecha_paso']   = $f['paso'];
					}
				} else {
					$up['estado'] = 'hecho';
				}
			}
			if ( $que === 'plan_real' ) $up['estado'] = 'hecho';
			if ( $que === 'descartar' ) $up['estado'] = 'descartado';
			if ( $que === 'reabrir' )   $up['estado'] = 'pendiente';

			if ( $que === 'editar' ) {
				$nota = sanitize_textarea_field( wp_unslash( $_POST['nota'] ?? '' ) );
				if ( $nota !== '' ) $up['nota'] = $nota;
				$fp = sanitize_text_field( $_POST['fecha_paso'] ?? '' );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fp ) ) $up['fecha_paso'] = $fp;
				$li = isset( $_POST['lleva_inicio'] ) ? 1 : 0;
				$up['lleva_inicio'] = $li;
			}

			$wpdb->update( $t, $up, array( 'submission_id' => $id ) );
		}

		wp_safe_redirect( wp_get_referer() ?: aumbral_app_url( self::SLUG ) );
		exit;
	}

	/* ───────────── Pantalla ───────────── */

	public function cuerpo( $ctx ) {
		$base = $ctx['base'];
		$this->sincronizar();

		$c    = $this->contadores();
		$hoy  = current_time( 'Y-m-d' );
		$v    = sanitize_key( $ctx['query']['v'] ?? 'pendientes' );

		if ( $v === 'vencen' )      $filas = $this->filas( "estado='inicio' AND fecha_paso <= %s", array( gmdate( 'Y-m-d', strtotime( $hoy . ' +7 days' ) ) ), 'fecha_paso ASC' );
		elseif ( $v === 'inicio' )  $filas = $this->filas( "estado='inicio'", array(), 'fecha_paso ASC' );
		elseif ( $v === 'hechos' )  $filas = $this->filas( "estado='hecho'", array(), 'fecha DESC', 40 );
		elseif ( $v === 'todos' )   $filas = $this->filas( '1=1', array(), 'fecha DESC', 40 );
		else                        $filas = $this->filas( "estado='pendiente'", array(), 'fecha ASC' );

		$todas  = $this->filas( "fecha >= %s", array( gmdate( 'Y-m-d', strtotime( '-30 days' ) ) ) );
		$avisos = $this->avisos( $todas );
		?>
 <div class="card">
 <?php if ( $c['pendiente'] || $c['vencen'] ) : ?>
  <div class="lbl">Por cargar en TrainingPeaks</div>
  <div class="big"><?php echo (int) ( $c['pendiente'] + $c['vencen'] ); ?></div>
  <div class="leg" style="margin-top:14px">
   <div><span class="dot" style="background:var(--r)"></span><b><?php echo (int) $c['pendiente']; ?></b> solicitudes nuevas</div>
   <div><span class="dot" style="background:var(--am)"></span><b><?php echo (int) $c['vencen']; ?></b> pasan al plan real</div>
  </div>
  <?php if ( $c['proximos'] ) : ?>
   <div class="lbl" style="margin-top:12px"><?php echo (int) $c['proximos']; ?> más vencen esta semana</div>
  <?php endif; ?>
 <?php else : ?>
  <div class="okh"><span class="em">&#128218;</span><div>
   <div style="font-size:17px;font-weight:600">Todo cargado</div>
   <div class="lbl" style="margin-top:3px"><?php echo (int) $c['inicio']; ?> en semanas de inicio, ninguna vencida</div>
  </div></div>
 <?php endif; ?>
 </div>

 <div class="chips">
 <?php
	$chips = array(
		'pendientes' => array( 'Pendientes', $c['pendiente'] ),
		'vencen'     => array( 'Vencen', $c['vencen'] ),
		'inicio'     => array( 'En inicio', $c['inicio'] ),
		'hechos'     => array( 'Hechos', 0 ),
		'todos'      => array( 'Todo', 0 ),
	);
	foreach ( $chips as $k => $x ) {
		printf( '<a class="%s" href="%s">%s%s</a>',
			$v === $k ? 'on' : '',
			esc_url( add_query_arg( 'v', $k, $base ) ),
			esc_html( $x[0] ),
			$x[1] ? ' <b>' . (int) $x[1] . '</b>' : '' );
	}
 ?>
 </div>

 <?php
	$tit = array(
		'pendientes' => array( 'Pendientes', 'por cargar' ),
		'vencen'     => array( 'Pasan al plan real', 'terminan las 2 semanas' ),
		'inicio'     => array( 'En semanas de inicio', 'cargadas, sin cerrar' ),
		'hechos'     => array( 'Cerradas', 'nada que hacer' ),
		'todos'      => array( 'Todas', 'sin filtrar' ),
	);
	$t = isset( $tit[ $v ] ) ? $tit[ $v ] : $tit['pendientes'];
 ?>
 <h2><?php echo esc_html( $t[0] ); ?> <span><?php echo count( $filas ) . ( in_array( $v, array( 'hechos', 'todos' ), true ) ? ' últimas' : '' ) . ' · ' . esc_html( $t[1] ); ?></span></h2>

 <?php if ( ! $filas ) : ?>
  <div class="zero"><span class="em">&#127937;</span><b>Nada por aquí</b><p>No hay solicitudes en esta vista.</p></div>
 <?php endif; ?>

 <?php foreach ( $filas as $f ) :
	$nom   = trim( $f['nombre'] . ' ' . $f['apellidos'] );
	$dias  = (int) floor( ( current_time( 'timestamp' ) - strtotime( $f['fecha'] ) ) / DAY_IN_SECONDS );
	$vence = $f['fecha_paso'] && $f['fecha_paso'] <= $hoy;
	$et    = array(
		'pendiente'  => array( 'Por cargar', '' ),
		'inicio'     => array( $vence ? 'Toca pasar al plan' : 'En inicio', $vence ? 'am' : 'az' ),
		'hecho'      => array( 'Hecho', 'gr' ),
		'descartado' => array( 'Descartada', 'gy' ),
	);
	$e = isset( $et[ $f['estado'] ] ) ? $et[ $f['estado'] ] : array( $f['estado'], 'gy' );
	$aviso = isset( $avisos[ $f['email'] ] ) && $f['estado'] === 'pendiente' ? $avisos[ $f['email'] ] : '';
	$brd = trim( (string) $f['mensaje'] ) !== '' ? $this->borrador( $f ) : '';
	$nuevo = ! $f['lleva_inicio'] && $f['estado'] === 'pendiente'
		&& preg_match( '/\\b(soy|somos|es mi)\\s+(nuev[oa]|primer)|nuev[oa]\\s+(en|por aqu)|primera vez|acabo de (empezar|entrar|suscribirme)|me acabo de suscribir/iu', (string) $f['mensaje'] );
 ?>
 <article class="caso<?php echo $f['estado'] === 'hecho' || $f['estado'] === 'descartado' ? ' q' : ''; ?>">
  <div class="f1">
   <span class="tag <?php echo esc_attr( $e[1] ); ?>"><?php echo esc_html( $e[0] ); ?></span>
   <span class="hace"><?php echo $dias === 0 ? 'hoy' : $dias . ' días'; ?></span>
  </div>
  <div class="nom"><?php echo esc_html( $nom ?: '(sin nombre)' ); ?></div>
  <div class="sub"><?php echo esc_html( $f['email'] ); ?></div>
  <div class="meta">
   <span class="pi"><b><?php echo esc_html( $f['modalidad'] ?: 'sin modalidad' ); ?></b></span>
   <span class="pi"><?php echo esc_html( str_replace( array( 'entrena-para-', 'entrenamiento-' ), '', $f['slug'] ) ); ?></span>
   <?php if ( $f['tipo'] === 'fw' ) : ?><span class="pi">FW</span><?php endif; ?>
   <?php if ( $f['lleva_inicio'] ) : ?>
    <span class="pi al">2 semanas de inicio</span>
   <?php else : ?>
    <span class="pi ok">plan directo</span>
   <?php endif; ?>
  </div>

  <?php if ( $f['estado'] === 'inicio' && $f['fecha_paso'] ) : ?>
   <div class="acc">Inicio el <?php echo esc_html( wp_date( 'j M', strtotime( $f['fecha_inicio'] ) ) ); ?>.
    <?php echo $vence ? 'Le toca el plan real desde el ' : 'Pasa al plan real el '; ?>
    <b><?php echo esc_html( wp_date( 'l j \d\e F', strtotime( $f['fecha_paso'] ) ) ); ?></b>.</div>
  <?php endif; ?>

  <?php if ( $aviso ) : ?><div class="acc" style="background:var(--rs);color:var(--r)"><?php echo esc_html( $aviso ); ?></div><?php endif; ?>

  <?php if ( $nuevo ) : ?><div class="acc" style="background:var(--ams);color:var(--am)">Dice en su mensaje que es nuevo, pero el formulario no lo pregunta. Si es su primer plan, cárgalo con las 2 semanas de inicio.</div><?php endif; ?>

  <?php if ( $brd ) : ?>
  <details open>
   <summary>Escribió un mensaje</summary>
   <div class="msg"><?php echo esc_html( $f['mensaje'] ); ?></div>
  </details>
  <details>
   <summary>Borrador de respuesta</summary>
   <div class="msg" id="b<?php echo (int) $f['submission_id']; ?>"><?php echo esc_html( $brd ); ?></div>
   <div class="bts">
    <button class="b" onclick="cp('b<?php echo (int) $f['submission_id']; ?>')">Copiar</button>
   </div>
  </details>
  <?php endif; ?>

  <?php if ( $f['nota'] ) : ?><div class="hecho">&#9998;<span><?php echo esc_html( $f['nota'] ); ?></span></div><?php endif; ?>

  <div class="bts">
   <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:contents">
    <?php wp_nonce_field( 'aumbral_planes' ); ?>
    <input type="hidden" name="action" value="aumbral_planes_accion">
    <input type="hidden" name="id" value="<?php echo (int) $f['submission_id']; ?>">
    <?php if ( $f['estado'] === 'pendiente' ) : ?>
     <button class="b<?php echo $f['lleva_inicio'] ? '' : ' p'; ?>" name="que" value="cargado_directo">Plan cargado</button>
     <button class="b<?php echo $f['lleva_inicio'] ? ' p' : ''; ?>" name="que" value="cargado_inicio">Cargado plan inicio</button>
     <button class="b o" name="que" value="descartar">Descartar</button>
    <?php elseif ( $f['estado'] === 'inicio' ) : ?>
     <button class="b p" name="que" value="plan_real">Pasado al plan real</button>
    <?php else : ?>
     <button class="b o" name="que" value="reabrir">Reabrir</button>
    <?php endif; ?>
   </form>
   <a class="b<?php echo $brd ? ' p' : ''; ?>" target="_blank" rel="noopener" href="https://mail.google.com/mail/?view=cm&fs=1&tf=1&to=<?php echo rawurlencode( $f['email'] ); ?>&su=<?php echo rawurlencode( 'Tu plan de entrenamiento en A Umbral' ); ?><?php echo $brd ? '&body=' . rawurlencode( $brd ) : ''; ?>"><?php echo $brd ? 'Responder' : 'Gmail'; ?></a>
   <?php if ( $f['user_id'] ) : ?>
    <a class="b o" target="_blank" rel="noopener" href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . (int) $f['user_id'] ) ); ?>">Ficha</a>
   <?php endif; ?>
  </div>

  <details>
   <summary>Ajustar</summary>
   <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'aumbral_planes' ); ?>
    <input type="hidden" name="action" value="aumbral_planes_accion">
    <input type="hidden" name="que" value="editar">
    <input type="hidden" name="id" value="<?php echo (int) $f['submission_id']; ?>">
    <div class="nf"><input type="date" name="fecha_paso" value="<?php echo esc_attr( $f['fecha_paso'] ); ?>"></div>
    <div class="nf"><input type="text" name="nota" placeholder="nota…" value="<?php echo esc_attr( $f['nota'] ); ?>"><button class="b">Guardar</button></div>
    <label class="lbl" style="display:block;margin-top:10px">
     <input type="checkbox" name="lleva_inicio" value="1" <?php checked( $f['lleva_inicio'], 1 ); ?>> lleva 2 semanas de inicio
    </label>
   </form>
  </details>
 </article>
 <?php endforeach; ?>

 <div class="caso" style="margin-top:18px">
  <div class="f1">
   <span class="tag <?php echo get_option( self::OPT_SILENCIO ) ? 'gr' : 'gy'; ?>">Aviso por correo</span>
  </div>
  <div class="acc" style="margin-top:12px">
   <?php if ( get_option( self::OPT_SILENCIO ) ) : ?>
    Los formularios <b>ya no llegan a Gmail</b>: se registran solo aquí.
    <?php $u = get_option( 'aumbral_planes_silencio_ultimo' ); $n = (int) get_option( self::OPT_CUENTA ); ?>
    <?php if ( $n ) : ?><br><?php echo (int) $n; ?> retenidos<?php echo $u ? ', el último el ' . esc_html( wp_date( 'j M \a \l\a\s H:i', strtotime( $u ) ) ) : ''; ?>.<?php endif; ?>
   <?php else : ?>
    Cada formulario sigue llegando a Gmail además de entrar aquí. Puedes silenciarlo cuando veas que la app no se deja ninguno.
   <?php endif; ?>
  </div>
  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
   <?php wp_nonce_field( 'aumbral_planes' ); ?>
   <input type="hidden" name="action" value="aumbral_planes_accion">
   <input type="hidden" name="que" value="correo">
   <div class="nf"><button class="b<?php echo get_option( self::OPT_SILENCIO ) ? ' o' : ''; ?>"><?php echo get_option( self::OPT_SILENCIO ) ? 'Volver a recibirlos en Gmail' : 'Dejar de recibirlos en Gmail'; ?></button></div>
  </form>
 </div>
 <?php
	}
}

add_action( 'plugins_loaded', function () {
	AUmbral_Planes::i();
	if ( (int) get_option( 'aumbral_planes_db' ) !== AUmbral_Planes::DB ) AUmbral_Planes::instalar();
}, 20 );

register_activation_hook( __FILE__, array( 'AUmbral_Planes', 'instalar' ) );
