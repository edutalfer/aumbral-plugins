<?php
/**
 * A Umbral · Resumen diario.
 * Un solo correo por la mañana con lo que hay que hacer hoy en las tres secciones.
 * Cada módulo aporta su bloque con el filtro «aumbral_app_resumen». Si no hay nada, no se envía.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class AUmbral_Resumen {

	const HOOK = 'aumbral_app_resumen_diario';
	const HORA = '07:30:00';
	const OPT  = 'aumbral_resumen_activo';

	private static $inst;
	public static function i() { return self::$inst ?: ( self::$inst = new self() ); }

	private function __construct() {
		add_action( self::HOOK, array( $this, 'enviar' ) );
		add_action( 'init', array( $this, 'programar' ) );
		// El aviso de tareas del tema queda absorbido por este resumen.
		add_filter( 'pre_wp_mail', array( $this, 'silenciar_aviso_tareas' ), 20, 2 );
	}

	public function programar() {
		if ( ! get_option( self::OPT, 1 ) ) return;
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$t = new DateTime( 'tomorrow ' . self::HORA, wp_timezone() );
			wp_schedule_event( $t->getTimestamp(), 'daily', self::HOOK );
		}
	}

	public function silenciar_aviso_tareas( $corto, $args ) {
		if ( null !== $corto || ! get_option( self::OPT, 1 ) ) return $corto;
		$asunto = isset( $args['subject'] ) ? (string) $args['subject'] : '';

		// Absorbidos por este resumen. La alerta inmediata de un fallo de pago
		// («[A Umbral] Contactar: …») NO se toca: avisa en el momento y eso sigue valiendo.
		$fuera = array( 'Tareas que vencen mañana', '[A Umbral] Pagos:' );
		foreach ( $fuera as $x ) {
			if ( strpos( $asunto, $x ) === 0 ) return true;
		}
		return $corto;
	}

	/** A quién va: los usuarios con acceso a la app. */
	private function destinatarios() {
		$ids = defined( 'AUMBRAL_TASKS_ALLOWED_USERS' ) ? AUMBRAL_TASKS_ALLOWED_USERS : array( 1 );
		$out = array();
		foreach ( $ids as $id ) {
			$u = get_userdata( $id );
			if ( $u && is_email( $u->user_email ) ) $out[ $id ] = $u->user_email;
		}
		return $out;
	}

	/**
	 * Reúne los bloques de cada módulo.
	 * Un bloque es: array( 'titulo', 'url', 'items' => array( array( 'texto', 'detalle', 'urgente' ) ) )
	 */
	public function bloques( $para_usuario = 0 ) {
		$b = apply_filters( 'aumbral_app_resumen', array(), $para_usuario );
		$out = array();
		foreach ( $b as $x ) {
			if ( ! empty( $x['items'] ) ) $out[] = $x;
		}
		return $out;
	}

	public function enviar( $forzar = false ) {
		if ( ! $forzar && ! get_option( self::OPT, 1 ) ) return 0;

		$enviados = 0;
		foreach ( $this->destinatarios() as $uid => $email ) {
			$bloques = $this->bloques( $uid );
			if ( ! $bloques ) continue; // día sin nada que hacer: no se molesta

			$n = 0;
			foreach ( $bloques as $x ) $n += count( $x['items'] );

			$u      = get_userdata( $uid );
			$nombre = $u->first_name ?: explode( ' ', $u->display_name )[0];
			$asunto = sprintf( '%d cosa%s para hoy · A Umbral', $n, $n === 1 ? '' : 's' );

			add_filter( 'wp_mail_content_type', array( $this, 'html' ) );
			$ok = wp_mail( $email, $asunto, $this->cuerpo( $nombre, $bloques ) );
			remove_filter( 'wp_mail_content_type', array( $this, 'html' ) );
			if ( $ok ) $enviados++;
		}
		update_option( 'aumbral_resumen_ultimo', current_time( 'mysql' ), false );
		return $enviados;
	}

	public function html() { return 'text/html'; }

	private function cuerpo( $nombre, $bloques ) {
		$app = home_url( '/app/' );
		$dia = ucfirst( wp_date( 'l j \d\e F' ) );

		$h  = '<div style="background:#fbfaf8;padding:26px 14px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#191614">';
		$h .= '<div style="max-width:560px;margin:0 auto">';
		$h .= '<div style="font-size:13px;color:#8c867f;letter-spacing:.04em;text-transform:uppercase">A Umbral</div>';
		$h .= '<div style="font-size:26px;font-weight:600;margin:6px 0 2px">Hola ' . esc_html( $nombre ) . '</div>';
		$h .= '<div style="font-size:14px;color:#8c867f;margin-bottom:22px">' . esc_html( $dia ) . '</div>';

		foreach ( $bloques as $b ) {
			$h .= '<div style="background:#fff;border-radius:16px;padding:20px;margin-bottom:14px">';
			$h .= '<div style="font-size:16px;font-weight:600;margin-bottom:14px">' . esc_html( $b['titulo'] ) . '</div>';
			foreach ( $b['items'] as $it ) {
				$col = ! empty( $it['urgente'] ) ? '#ed4044' : '#cfcfcf';
				$h  .= '<div style="border-left:3px solid ' . $col . ';padding:2px 0 2px 12px;margin-bottom:12px">';
				$h  .= '<div style="font-size:14.5px;font-weight:500">' . esc_html( $it['texto'] ) . '</div>';
				if ( ! empty( $it['detalle'] ) ) {
					$h .= '<div style="font-size:13px;color:#8c867f;margin-top:2px">' . esc_html( $it['detalle'] ) . '</div>';
				}
				$h .= '</div>';
			}
			if ( ! empty( $b['url'] ) ) {
				$h .= '<a href="' . esc_url( $b['url'] ) . '" style="font-size:13px;color:#ed4044;text-decoration:none;font-weight:600">Abrir &rarr;</a>';
			}
			$h .= '</div>';
		}

		$h .= '<div style="text-align:center;margin:22px 0 6px">';
		$h .= '<a href="' . esc_url( $app ) . '" style="background:#ed4044;color:#fff;text-decoration:none;font-size:14px;font-weight:600;padding:13px 26px;border-radius:99px;display:inline-block">Ir a la app</a>';
		$h .= '</div>';
		$h .= '<div style="text-align:center;font-size:11.5px;color:#b4aea7;margin-top:18px">Resumen automático de la app interna.</div>';
		$h .= '</div></div>';
		return $h;
	}
}

add_action( 'plugins_loaded', function () { AUmbral_Resumen::i(); }, 30 );

register_deactivation_hook(
	dirname( __DIR__ ) . '/aumbral-app/aumbral-app.php',
	function () { wp_clear_scheduled_hook( AUmbral_Resumen::HOOK ); }
);
