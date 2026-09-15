<?php
/**
 * Plugin Name: A Umbral · Revisión de pagos
 * Description: Detección, clasificación y seguimiento de fallos de renovación (WooCommerce Subscriptions + Stripe). Panel de triaje, alerta inmediata cuando hace falta contactar y resumen semanal.
 * Version: 1.0.0
 * Author: A Umbral
 * Requires Plugins: woocommerce, woocommerce-subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class AUP_Pagos {

	const OPT        = 'aup_pagos_settings';
	const META_GEST  = '_aup_pagos_gestion';
	const META_ALERT = '_aup_pagos_alerta';
	const CRON_DIG   = 'aup_pagos_digest';
	const CRON_ALERT = 'aup_pagos_alerta';
	const SLUG       = 'aup-pagos';
	const VENTANA    = 90; // días hacia atrás para buscar renovaciones fallidas

	private static $inst;
	public static function i() { return self::$inst ?: ( self::$inst = new self() ); }

	private function __construct() {
		add_action( 'admin_menu',               array( $this, 'menu' ) );
		add_action( 'admin_post_aup_pagos_gestionar', array( $this, 'act_gestionar' ) );
		add_action( 'admin_post_aup_pagos_ajustes',   array( $this, 'act_ajustes' ) );
		add_action( 'admin_post_aup_pagos_digest_now', array( $this, 'act_digest_now' ) );

		// Detección en tiempo real
		add_action( 'woocommerce_subscription_renewal_payment_failed', array( $this, 'on_fail' ), 20, 2 );
		add_action( 'woocommerce_subscriptions_after_payment_retry',   array( $this, 'on_retry' ), 20, 2 );
		add_action( self::CRON_ALERT, array( $this, 'alerta' ) );
		add_action( self::CRON_DIG,   array( $this, 'digest' ) );

		// Silenciar el correo de admin "Reintento de pago" (lo sustituye el resumen)
		add_filter( 'woocommerce_email_enabled_payment_retry', array( $this, 'silenciar_retry' ), 20, 2 );
		// Silenciar el correo de admin "Pedido fallido" SOLO para renovaciones (las cubre la app); las altas nuevas fallidas siguen avisando
		add_filter( 'woocommerce_email_enabled_failed_order', array( $this, 'silenciar_failed' ), 20, 2 );

		add_action( 'init', array( $this, 'asegurar_cron' ) );
	}

	/* ─────────────────────────── Ajustes ─────────────────────────── */

	public function opts() {
		$d = array(
			'email'            => get_option( 'admin_email' ),
			'silenciar_retry'  => 1,
			'silenciar_failed' => 1,
			'alerta_inmediata' => 1,
			'digest_hora'      => '08:00',
		);
		return wp_parse_args( (array) get_option( self::OPT, array() ), $d );
	}

	public function silenciar_retry( $enabled, $email ) {
		return $this->opts()['silenciar_retry'] ? false : $enabled;
	}

	public function silenciar_failed( $enabled, $order ) {
		if ( ! $this->opts()['silenciar_failed'] || ! $order || ! is_a( $order, 'WC_Order' ) ) return $enabled;
		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) return false;
		return $enabled;
	}

	public function asegurar_cron() {
		if ( ! wp_next_scheduled( self::CRON_DIG ) ) {
			$h  = $this->opts()['digest_hora'];
			$dt = new DateTime( 'next monday ' . $h, wp_timezone() );
			wp_schedule_event( $dt->getTimestamp(), 'weekly', self::CRON_DIG );
		}
	}

	/* ─────────────────────────── Detección ─────────────────────────── */

	public function on_fail( $subscription, $last_order = null ) {
		$this->programar_alerta( $subscription );
	}
	public function on_retry( $retry, $last_order = null ) {
		if ( ! $last_order ) return;
		foreach ( wcs_get_subscriptions_for_renewal_order( $last_order ) as $s ) $this->programar_alerta( $s );
	}
	private function programar_alerta( $s ) {
		if ( ! $this->opts()['alerta_inmediata'] ) return;
		$id = is_object( $s ) ? $s->get_id() : (int) $s;
		if ( ! $id ) return;
		// +10 min: dejamos que Stripe/WCS terminen de escribir las notas
		if ( ! wp_next_scheduled( self::CRON_ALERT, array( $id ) ) ) {
			wp_schedule_single_event( time() + 600, self::CRON_ALERT, array( $id ) );
		}
	}

	/** Cron: evalúa una suscripción y avisa si el veredicto es CONTACTAR (una vez por ciclo de fallo). */
	public function alerta( $sub_id ) {
		$s = wcs_get_subscription( $sub_id );
		if ( ! $s ) return;
		$c = $this->caso( $s );
		if ( ! $c || $c['veredicto'] !== 'CONTACTAR' ) return;
		if ( (int) $s->get_meta( self::META_ALERT ) === (int) $c['order_id'] ) return; // ya avisado en este ciclo

		$s->update_meta_data( self::META_ALERT, $c['order_id'] );
		$s->save();

		$asunto = sprintf( '[A Umbral] Contactar: %s · %s', $c['cliente'], $c['motivo_es'] );
		$html   = $this->email_wrap( '<h1>Hay que contactar con un cliente</h1>' . $this->email_caso( $c, true ) );
		$this->enviar( $asunto, $html );
	}

	/* ─────────────────────────── Casos ─────────────────────────── */

	/** Devuelve todos los casos relevantes, indexados por ID de suscripción. */
	public function casos() {
		$subs = array();
		$desde = time() - self::VENTANA * DAY_IN_SECONDS;

		// 1) Renovaciones fallidas / pendientes en la ventana
		$oids = wc_get_orders( array(
			'type'         => 'shop_order',
			'status'       => array( 'wc-failed', 'wc-pending' ),
			'date_created' => '>' . $desde,
			'limit'        => -1,
			'return'       => 'ids',
		) );
		foreach ( $oids as $oid ) {
			if ( ! wcs_order_contains_renewal( $oid ) ) continue;
			foreach ( wcs_get_subscriptions_for_renewal_order( $oid ) as $s ) $subs[ $s->get_id() ] = $s;
		}

		// 1b) Altas que nunca llegaron a pagar (el fallo fue el pedido inicial, no una renovación)
		foreach ( wcs_get_subscriptions( array( 'subscription_status' => array( 'on-hold', 'pending' ), 'subscriptions_per_page' => -1 ) ) as $s ) {
			$subs[ $s->get_id() ] = $s;
		}

		// 2) Suscripciones en espera (aunque el fallo sea antiguo)
		foreach ( wcs_get_subscriptions( array( 'subscription_status' => 'on-hold', 'subscriptions_per_page' => -1 ) ) as $s ) {
			$subs[ $s->get_id() ] = $s;
		}

		// 3) Renovaciones recuperadas en los últimos 14 días (reintento completado)
		if ( class_exists( 'WCS_Retry_Manager' ) ) {
			$ok = WCS_Retry_Manager::store()->get_retries( array( 'status' => 'complete', 'limit' => 60, 'orderby' => 'ID', 'order' => 'DESC' ) );
			foreach ( $ok as $r ) {
				if ( $r->get_time() < time() - 14 * DAY_IN_SECONDS ) continue;
				foreach ( wcs_get_subscriptions_for_renewal_order( $r->get_order_id() ) as $s ) {
					if ( ! isset( $subs[ $s->get_id() ] ) ) $subs[ $s->get_id() ] = $s;
				}
			}
		}

		$out = array();
		foreach ( $subs as $id => $s ) {
			$c = $this->caso( $s );
			if ( $c ) $out[ $id ] = $c;
		}
		// Orden: CONTACTAR → REVISAR → ESPERAR → PERDIDA → RESUELTO, y dentro por días de fallo desc
		$peso = array( 'CONTACTAR' => 0, 'REVISAR' => 1, 'ESPERAR' => 2, 'ALTA' => 3, 'PERDIDA' => 4, 'DUPLICADA' => 5, 'SUSTITUIDA' => 6, 'RESUELTO' => 7 );
		uasort( $out, function ( $a, $b ) use ( $peso ) {
			if ( $a['gestionado'] !== $b['gestionado'] ) return $a['gestionado'] ? 1 : -1;
			if ( $peso[ $a['veredicto'] ] !== $peso[ $b['veredicto'] ] ) return $peso[ $a['veredicto'] ] - $peso[ $b['veredicto'] ];
			return $b['dias'] - $a['dias'];
		} );
		return $out;
	}

	/** Construye el caso de una suscripción. */
	public function caso( WC_Subscription $s ) {
		$o    = $s->get_last_order( 'all', array( 'renewal' ) );
		$alta = false;
		if ( ! $o ) {
			// Sin renovaciones: puede ser un alta que nunca se completó
			$p = $s->get_parent();
			if ( ! $p ) return null;
			$pagado = false;
			foreach ( $s->get_related_orders( 'all', 'any' ) as $ro ) {
				if ( in_array( $ro->get_status(), array( 'processing', 'completed' ), true ) ) { $pagado = true; break; }
			}
			if ( $pagado ) return null;   // pagó alguna vez: no es un alta fallida
			$o    = $p;
			$alta = true;
		}

		$oid    = $o->get_id();
		$ostat  = $o->get_status();
		$sstat  = $s->get_status();
		$fecha  = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : time();
		$dias   = (int) floor( ( time() - $fecha ) / DAY_IN_SECONDS );

		// Reintentos
		$hechos = 0; $pend = 0; $prox = 0; $cancel = 0;
		if ( class_exists( 'WCS_Retry_Manager' ) ) {
			foreach ( WCS_Retry_Manager::store()->get_retries( array( 'order_id' => $oid ) ) as $r ) {
				$st = $r->get_status();
				if ( $st === 'pending' ) { $pend++; $prox = $prox ? min( $prox, $r->get_time() ) : $r->get_time(); }
				elseif ( $st === 'cancelled' ) $cancel++;
				else $hechos++;
			}
		}

		// Motivo
		list( $codigo, $bruto ) = $this->motivo( $oid );
		$def = $this->def( $codigo );

		// Veredicto
		$agotado = ( $pend === 0 && in_array( $ostat, array( 'failed', 'pending' ), true ) );
		if ( $alta ) {
			$v = in_array( $ostat, array( 'processing', 'completed' ), true ) ? 'RESUELTO' : 'ALTA';
		} elseif ( in_array( $sstat, array( 'cancelled', 'expired' ), true ) && in_array( $ostat, array( 'failed', 'pending', 'cancelled' ), true ) ) {
			$v = 'PERDIDA';
		} elseif ( in_array( $ostat, array( 'processing', 'completed' ), true ) ) {
			$v = 'RESUELTO';
		} elseif ( $def['duro'] ) {
			$v = 'CONTACTAR';
		} elseif ( $codigo === 'ninguno' ) {
			$v = $agotado ? 'CONTACTAR' : 'REVISAR';
		} elseif ( $def['espera'] ) {          // fondos, límite
			$v = $agotado ? 'CONTACTAR' : 'ESPERAR';
		} else {                                // rechazo genérico
			$v = ( $agotado || $hechos >= 2 ) ? 'CONTACTAR' : 'ESPERAR';
		}

		$accion = $def['accion'];
		if ( $v === 'CONTACTAR' && $agotado && ! $def['duro'] ) $accion = 'Reintentos agotados. Escríbele con el enlace de pago: Stripe ya no lo va a intentar.';
		if ( $v === 'PERDIDA' )  $accion = 'Suscripción cancelada tras los fallos. Solo recuperable con un mensaje personal y alta nueva.';
		if ( $v === 'RESUELTO' ) $accion = 'Un reintento cobró. Nada que hacer.';
		if ( $v === 'ALTA' ) {
			$accion = sprintf( 'Se dio de alta el %s y el primer pago nunca llegó a completarse: no ha pagado ni un euro. No hay reintentos programados, así que solo se activa si él paga el enlace. Precio congelado de entonces: %s.', wp_date( 'd/m/Y', strtotime( $s->get_date( 'start_date' ) ) ), html_entity_decode( wp_strip_all_tags( wc_price( $o->get_total() ) ), ENT_QUOTES, 'UTF-8' ) );
		}
		if ( $v === 'ESPERAR' && $prox ) $accion .= ' Próximo reintento: ' . $this->f( $prox ) . '.';

		// ¿El cliente se dio de alta otra vez después de este fallo?
		$rep = $this->reemplazo( $s );
		if ( $rep && $v !== 'RESUELTO' ) {
			$rs    = $rep->get_status();
			$desde = wp_date( 'd/m/Y', strtotime( $rep->get_date( 'start_date' ) ) );
			if ( in_array( $rs, array( 'active', 'pending-cancel' ), true ) ) {
				$v = 'SUSTITUIDA';
				$accion = sprintf( 'El cliente volvió a darse de alta: suscripción #%d, %s desde el %s. Este fallo es historia; no hay que contactar.', $rep->get_id(), $rs === 'active' ? 'activa' : 'en cancelación', $desde );
			} elseif ( in_array( $rs, array( 'on-hold', 'pending' ), true ) ) {
				$v = 'DUPLICADA';
				$accion = sprintf( 'Hay una suscripción posterior del mismo cliente (#%d, %s desde el %s) con el problema vivo. Gestiona esa, no esta: escribirle dos veces por lo mismo confunde.', $rep->get_id(), $rs, $desde );
			}
		}

		// Gestión manual
		$g = (array) $s->get_meta( self::META_GEST );
		$gestionado = ! empty( $g['order_id'] ) && (int) $g['order_id'] === $oid;

		$nombre = trim( $s->get_billing_first_name() . ' ' . $s->get_billing_last_name() ) ?: $s->get_billing_email();
		$url_cambio = method_exists( $s, 'get_change_payment_method_url' ) ? $s->get_change_payment_method_url() : $s->get_view_order_url();
		$url_pago   = ( $o->needs_payment() ) ? $o->get_checkout_payment_url() : '';

		$mensaje = $this->mensaje( $def, $s->get_billing_first_name() ?: 'ciclista', $url_cambio, $url_pago );
		if ( $v === 'ALTA' ) {
			$mensaje = "Hola " . ( $s->get_billing_first_name() ?: 'ciclista' ) . ",\n\nRevisando la plataforma he visto que tu alta del " . wp_date( 'j \d\e F \d\e Y', strtotime( $s->get_date( 'start_date' ) ) ) . " se quedó a medias: el primer pago no llegó a completarse y la suscripción nunca se activó.\n\nSi te quedaste con ganas, aquí puedes terminarlo, y mantienes la tarifa de entonces:\n\n" . ( $url_pago ?: $url_cambio ) . "\n\nY si ya no te interesa, dímelo y lo cierro sin más. Sin compromiso.\n\nEduardo Talavera\nFundador y Entrenador en A Umbral";
		}

		return array(
			'sub_id'      => $s->get_id(),
			'sub_status'  => $sstat,
			'order_id'    => $oid,
			'order_status'=> $ostat,
			'cliente'     => $nombre,
			'nombre_pila' => $s->get_billing_first_name() ?: 'ciclista',
			'email'       => $s->get_billing_email(),
			'importe'     => html_entity_decode( wp_strip_all_tags( wc_price( $o->get_total() ) ), ENT_QUOTES, 'UTF-8' ) . ' / ' . $this->periodo( $s ),
			'fecha'       => $fecha,
			'dias'        => $dias,
			'reintentos'  => $hechos,
			'pendientes'  => $pend,
			'cancelados'  => $cancel,
			'proximo'     => $prox,
			'codigo'      => $codigo,
			'motivo_es'   => $def['es'],
			'motivo_raw'  => $bruto,
			'veredicto'   => $v,
			'accion'      => $accion,
			'mensaje'     => $mensaje,
			'url_cambio'  => $url_cambio,
			'url_pago'    => $url_pago,
			'url_admin'   => admin_url( 'post.php?post=' . $s->get_id() . '&action=edit' ),
			'url_pedido'  => admin_url( 'post.php?post=' . $oid . '&action=edit' ),
			'gestionado'  => $gestionado,
			'gestion'     => $gestionado ? $g : null,
		);
	}

	/** Suscripción posterior del mismo cliente, si existe (para detectar altas nuevas tras un fallo). */
	private function reemplazo( WC_Subscription $s ) {
		$uid = (int) $s->get_user_id();
		if ( ! $uid ) return null;
		static $cache = array();
		if ( ! isset( $cache[ $uid ] ) ) $cache[ $uid ] = wcs_get_users_subscriptions( $uid );
		$ini  = strtotime( $s->get_date( 'start_date' ) );
		$best = null;
		foreach ( $cache[ $uid ] as $x ) {
			if ( $x->get_id() === $s->get_id() ) continue;
			if ( strtotime( $x->get_date( 'start_date' ) ) <= $ini ) continue;
			if ( ! $best || strtotime( $x->get_date( 'start_date' ) ) > strtotime( $best->get_date( 'start_date' ) ) ) $best = $x;
		}
		return $best;
	}

	private function periodo( $s ) {
		$p = $s->get_billing_period(); $i = (int) $s->get_billing_interval();
		$m = array( 'day' => 'día', 'week' => 'semana', 'month' => 'mes', 'year' => 'año' );
		$t = isset( $m[ $p ] ) ? $m[ $p ] : $p;
		return $i > 1 ? "$i {$t}es" : $t;
	}

	/** Extrae el código de motivo a partir de las notas del pedido. Devuelve [codigo, texto_bruto]. */
	private function motivo( $oid ) {
		$notas = wc_get_order_notes( array( 'order_id' => $oid, 'limit' => 100 ) );
		$txt = '';
		$ultimo = '';
		foreach ( $notas as $n ) {
			$c = wp_strip_all_tags( $n->content );
			if ( stripos( $c, 'correo electrónico' ) !== false ) continue;
			if ( stripos( $c, 'Regla de reintento' ) !== false ) continue;
			if ( preg_match( '/^El estado del pedido cambió/u', $c ) ) continue;
			$txt .= ' ' . $c;
			if ( ! $ultimo && preg_match( '/Motivo:\s*(.+?)\.\s*El estado/u', $c, $m ) ) $ultimo = $m[1];
			if ( ! $ultimo && stripos( $c, 'Radar' ) !== false ) $ultimo = 'Stripe Radar bloqueó el pago';
		}
		// Prioridad: los motivos duros primero, los genéricos al final
		$pat = array(
			'radar'         => '/previously_declined_do_not_retry|Radar ha bloqueado/iu',
			'no_recurrente' => '/does not support this type of purchase|no admite este tipo de compra/iu',
			'sca'           => '/failed authentication|authentication required|Additional verification|requires? authentication|autenticaci[oó]n/iu',
			'link'          => '/Link account|Link consumer/iu',
			'caducada'      => '/has expired|expired_card|caducad/iu',
			'numero'        => '/number is incorrect|incorrect_number|tarjeta es incorrecto|incorrect_cvc|cvc es incorrecto/iu',
			'cuenta'        => '/Invalid account|no such (customer|payment|source)|does not exist|resource_missing/iu',
			'fondos'        => '/insufficient funds|fondos insuficientes/iu',
			'limite'        => '/repeated attempts|exceeding its amount limit|too frequently|velocity|withdrawal_count_limit/iu',
			'generico'      => '/card was declined|tarjeta ha sido rechazada|do not honor|generic_decline|payment failed|pago ha fallado|fallo en el pago/iu',
		);
		foreach ( $pat as $k => $re ) if ( preg_match( $re, $txt ) ) return array( $k, $ultimo ?: trim( mb_substr( $txt, 0, 120 ) ) );
		return array( 'ninguno', $ultimo ?: trim( mb_substr( $txt, 0, 120 ) ) );
	}

	/** Definición de cada código: nombre en español, si es "duro" (nunca se arregla solo), si conviene esperar, acción y texto al cliente. */
	private function def( $k ) {
		$d = array(
			'radar' => array( 'es' => 'Bloqueo de Stripe Radar', 'duro' => true, 'espera' => false,
				'accion' => 'Stripe ha cancelado los reintentos automáticos. Nadie lo va a volver a intentar: escríbele hoy con el enlace para cambiar de tarjeta.',
				'cliente' => 'el sistema de pagos ha bloqueado tu tarjeta después de varios intentos fallidos y ya no volverá a intentarlo por su cuenta. Para reactivar la suscripción solo tienes que añadir una tarjeta (puede ser la misma si tu banco la ha desbloqueado) desde aquí:' ),
			'no_recurrente' => array( 'es' => 'Tarjeta sin soporte para pagos recurrentes', 'duro' => true, 'espera' => false,
				'accion' => 'La tarjeta no admite cobros periódicos (típico de prepago, algunas de débito y tarjetas virtuales). No funcionará nunca. Pedir otra tarjeta.',
				'cliente' => 'tu tarjeta no admite pagos recurrentes (suele pasar con tarjetas prepago, virtuales o algunas de débito), así que la renovación va a fallar siempre con ella. Puedes cambiarla por otra en un minuto desde aquí:' ),
			'sca' => array( 'es' => 'Requiere autenticación del banco (SCA)', 'duro' => true, 'espera' => false,
				'accion' => 'El banco exige que el cliente confirme el pago (SMS/app). Los reintentos automáticos no pueden hacerlo. Enviarle el enlace de pago para que autentique.',
				'cliente' => 'tu banco pide que confirmes este pago tú mismo (lo habitual es un SMS o una notificación en la app del banco), y eso no puede hacerlo el sistema automático. Puedes completarlo en un momento desde aquí:' ),
			'link' => array( 'es' => 'Problema con Stripe Link', 'duro' => true, 'espera' => false,
				'accion' => 'El cliente pagó con Stripe Link y la conexión se ha cerrado o exige verificación. Pedirle que introduzca la tarjeta directamente, sin Link.',
				'cliente' => 'el método con el que pagaste (Link, el guardado de tarjetas de Stripe) ha dejado de estar conectado. La solución es introducir la tarjeta directamente, sin usar Link, desde aquí:' ),
			'caducada' => array( 'es' => 'Tarjeta caducada', 'duro' => true, 'espera' => false,
				'accion' => 'Tarjeta caducada. Pedir la nueva.',
				'cliente' => 'la tarjeta que tenemos guardada ha caducado. Solo hay que actualizarla con la nueva desde aquí:' ),
			'numero' => array( 'es' => 'Datos de tarjeta incorrectos', 'duro' => true, 'espera' => false,
				'accion' => 'Número o CVC inválidos (tarjeta reemplazada o robada). Pedir tarjeta nueva.',
				'cliente' => 'los datos de la tarjeta guardada ya no son válidos (suele ser que el banco la ha reemplazado). Puedes añadir la nueva desde aquí:' ),
			'cuenta' => array( 'es' => 'Método de pago inexistente', 'duro' => true, 'espera' => false,
				'accion' => 'El método de pago ya no existe en Stripe. Pedir que añada una tarjeta.',
				'cliente' => 'el método de pago que teníamos guardado ya no está disponible. Puedes añadir una tarjeta desde aquí:' ),
			'fondos' => array( 'es' => 'Fondos insuficientes', 'duro' => false, 'espera' => true,
				'accion' => 'Suele resolverse solo en los reintentos (nómina, traspaso). Esperar.',
				'cliente' => 'el banco ha rechazado la renovación por saldo insuficiente en ese momento. El sistema lo reintenta solo unos días más; si prefieres cambiar de tarjeta puedes hacerlo aquí:' ),
			'limite' => array( 'es' => 'Límite del banco / demasiados intentos', 'duro' => false, 'espera' => true,
				'accion' => 'El banco frena por frecuencia o límite de gasto. Esperar al siguiente reintento.',
				'cliente' => 'tu banco ha frenado el cobro por límite de gasto o por demasiados intentos seguidos. Se reintentará solo en unos días; si quieres adelantarlo puedes pagarlo aquí:' ),
			'generico' => array( 'es' => 'Rechazo genérico del banco', 'duro' => false, 'espera' => false,
				'accion' => 'Sin motivo concreto. Dar dos reintentos; si sigue fallando, contactar.',
				'cliente' => 'tu banco ha rechazado la renovación sin indicar el motivo. Lo más rápido es revisar la tarjeta o cambiarla desde aquí:' ),
			'ninguno' => array( 'es' => 'Sin motivo registrado', 'duro' => false, 'espera' => false,
				'accion' => 'No hay nota de Stripe en el pedido. Revisar a mano.',
				'cliente' => 'ha fallado la renovación de tu suscripción. Puedes revisar o cambiar la tarjeta desde aquí:' ),
		);
		return $d[ $k ] ?? $d['ninguno'];
	}

	private function mensaje( $def, $nombre, $url_cambio, $url_pago ) {
		$url = $url_pago ?: $url_cambio;
		return "Hola $nombre,\n\nTe escribo porque " . $def['cliente'] . "\n\n$url\n\nEn cuanto lo hagas, la suscripción se reactiva sola y no pierdes nada del plan. Si te surge cualquier duda, respóndeme a este correo.\n\nEduardo Talavera\nFundador y Entrenador en A Umbral";
	}

	/* ─────────────────────────── Acciones admin ─────────────────────────── */

	public function act_gestionar() {
		check_admin_referer( 'aup_pagos_gestionar' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No autorizado' );
		$sub = wcs_get_subscription( absint( $_POST['sub_id'] ?? 0 ) );
		if ( $sub ) {
			if ( ! empty( $_POST['deshacer'] ) ) {
				$sub->delete_meta_data( self::META_GEST );
			} else {
				$sub->update_meta_data( self::META_GEST, array(
					'order_id' => absint( $_POST['order_id'] ?? 0 ),
					'nota'     => sanitize_textarea_field( $_POST['nota'] ?? '' ),
					'fecha'    => time(),
					'por'      => wp_get_current_user()->display_name,
				) );
				$sub->add_order_note( 'Revisión de pagos: marcado como gestionado. ' . sanitize_textarea_field( $_POST['nota'] ?? '' ) );
			}
			$sub->save();
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=' . self::SLUG ) ); exit;
	}

	public function act_ajustes() {
		check_admin_referer( 'aup_pagos_ajustes' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No autorizado' );
		$o = array(
			'email'            => sanitize_email( $_POST['email'] ?? '' ) ?: get_option( 'admin_email' ),
			'silenciar_retry'  => empty( $_POST['silenciar_retry'] ) ? 0 : 1,
			'silenciar_failed' => empty( $_POST['silenciar_failed'] ) ? 0 : 1,
			'alerta_inmediata' => empty( $_POST['alerta_inmediata'] ) ? 0 : 1,
			'digest_hora'      => preg_match( '/^\d{2}:\d{2}$/', $_POST['digest_hora'] ?? '' ) ? $_POST['digest_hora'] : '08:00',
		);
		update_option( self::OPT, $o );
		wp_clear_scheduled_hook( self::CRON_DIG );
		$this->asegurar_cron();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&ok=ajustes' ) ); exit;
	}

	public function act_digest_now() {
		check_admin_referer( 'aup_pagos_digest_now' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No autorizado' );
		$this->digest();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&ok=digest' ) ); exit;
	}

	/* ─────────────────────────── Correo ─────────────────────────── */

	private function enviar( $asunto, $html ) {
		$to     = $this->opts()['email'];
		$desde  = get_option( 'woocommerce_email_from_address' );
		$nombre = get_option( 'woocommerce_email_from_name' );
		if ( ! is_email( $desde ) ) $desde = 'info@' . wp_parse_url( home_url(), PHP_URL_HOST );
		$desde  = str_replace( 'www.', '', $desde );
		$h = array( 'Content-Type: text/html; charset=UTF-8' );
		$h[] = sprintf( 'From: %s <%s>', $nombre ? $nombre : 'A Umbral', $desde );
		$h[] = sprintf( 'Reply-To: %s', $desde );
		return wp_mail( $to, $asunto, $html, $h );
	}

	public function digest() {
		$c  = $this->casos();
		$g  = array( 'CONTACTAR' => array(), 'REVISAR' => array(), 'ESPERAR' => array(), 'ALTA' => array(), 'PERDIDA' => array(), 'DUPLICADA' => array(), 'SUSTITUIDA' => array(), 'RESUELTO' => array() );
		foreach ( $c as $x ) if ( ! $x['gestionado'] ) $g[ $x['veredicto'] ][] = $x;

		$h  = '<h1>Revisión de pagos · semana del ' . $this->f( time(), 'd/m' ) . '</h1>';
		$h .= '<p class="kpi"><b style="color:#ed4044">' . count( $g['CONTACTAR'] ) . '</b> contactar &nbsp;·&nbsp; <b>' . count( $g['REVISAR'] ) . '</b> revisar &nbsp;·&nbsp; <b>' . count( $g['ESPERAR'] ) . '</b> esperar &nbsp;·&nbsp; <b>' . count( $g['PERDIDA'] ) . '</b> perdidas &nbsp;·&nbsp; <b>' . count( $g['RESUELTO'] ) . '</b> resueltas solas</p>';

		$tit = array( 'CONTACTAR' => 'Escribir hoy', 'REVISAR' => 'Revisar a mano', 'ESPERAR' => 'Esperando reintentos', 'ALTA' => 'Altas que nunca se completaron', 'PERDIDA' => 'Perdidas (últimos ' . self::VENTANA . ' días)', 'DUPLICADA' => 'Duplicadas (gestiona la más reciente)', 'SUSTITUIDA' => 'El cliente se dio de alta otra vez', 'RESUELTO' => 'Resueltas solas (14 días)' );
		foreach ( $tit as $k => $t ) {
			if ( ! $g[ $k ] ) continue;
			$h .= '<h2>' . $t . '</h2>';
			foreach ( $g[ $k ] as $x ) $h .= $this->email_caso( $x, $k === 'CONTACTAR' );
		}
		if ( ! array_filter( $g ) ) $h .= '<p>Sin incidencias de pago. Semana limpia.</p>';
		$h .= '<p style="margin-top:28px"><a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '" style="color:#ed4044">Abrir el panel de revisión →</a></p>';

		$this->enviar( '[A Umbral] Pagos: ' . count( $g['CONTACTAR'] ) . ' por contactar · ' . count( $g['ESPERAR'] ) . ' en espera', $this->email_wrap( $h ) );
	}

	private function email_caso( $x, $con_mensaje = false ) {
		$col = array( 'CONTACTAR' => '#ed4044', 'REVISAR' => '#ed4044', 'ESPERAR' => '#292929', 'ALTA' => '#8a6d3b', 'PERDIDA' => '#8a8a8a', 'DUPLICADA' => '#8a8a8a', 'SUSTITUIDA' => '#2d7a3a', 'RESUELTO' => '#2d7a3a' );
		$h  = '<table width="100%" cellpadding="0" cellspacing="0" style="border-top:2px solid ' . $col[ $x['veredicto'] ] . ';margin:14px 0;background:#fff"><tr><td style="padding:14px 16px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;line-height:1.55;color:#1a1a1a">';
		$h .= '<div><span style="background:' . $col[ $x['veredicto'] ] . ';color:#fff;padding:2px 8px;font-size:11px;letter-spacing:.08em">' . $x['veredicto'] . '</span> &nbsp; <b>' . esc_html( $x['cliente'] ) . '</b> &nbsp;<span style="color:#666">' . esc_html( $x['email'] ) . '</span></div>';
		$h .= '<div style="margin-top:6px;color:#444">' . esc_html( $x['importe'] ) . ' · fallo hace ' . $x['dias'] . ' d · reintentos ' . $x['reintentos'] . ( $x['pendientes'] ? ' (+' . $x['pendientes'] . ' programado)' : '' ) . '</div>';
		$h .= '<div style="margin-top:6px"><b>' . esc_html( $x['motivo_es'] ) . '</b>' . ( $x['motivo_raw'] ? ' <span style="color:#888">— ' . esc_html( $x['motivo_raw'] ) . '</span>' : '' ) . '</div>';
		$h .= '<div style="margin-top:6px">' . esc_html( $x['accion'] ) . '</div>';
		$h .= '<div style="margin-top:8px"><a href="' . esc_url( $x['url_admin'] ) . '" style="color:#ed4044">Suscripción #' . $x['sub_id'] . '</a> &nbsp;·&nbsp; <a href="' . esc_url( $x['url_pedido'] ) . '" style="color:#ed4044">Pedido #' . $x['order_id'] . '</a></div>';
		if ( $con_mensaje ) $h .= '<div style="margin-top:12px;padding:12px;background:#f5f4f0;white-space:pre-wrap;color:#292929">' . esc_html( $x['mensaje'] ) . '</div>';
		$h .= '</td></tr></table>';
		return $h;
	}

	private function email_wrap( $inner ) {
		return '<!doctype html><html><body style="margin:0;background:#f5f4f0;padding:24px"><div style="max-width:640px;margin:0 auto;font-family:ui-monospace,Menlo,Consolas,monospace;color:#1a1a1a">'
			. '<div style="font-size:11px;letter-spacing:.14em;color:#ed4044;margin-bottom:10px">A UMBRAL · REVISIÓN DE PAGOS</div>'
			. '<style>h1{font:400 20px/1.25 -apple-system,Helvetica,Arial,sans-serif;margin:0 0 10px}h2{font:400 13px/1.3 ui-monospace,Menlo,monospace;letter-spacing:.1em;text-transform:uppercase;margin:28px 0 6px;color:#292929}p{font-size:13px;line-height:1.55}</style>'
			. $inner . '</div></body></html>';
	}

	private function f( $ts, $fmt = 'd/m/Y H:i' ) { return wp_date( $fmt, $ts ); }

	/* ─────────────────────────── Panel ─────────────────────────── */

	public function menu() {
		add_submenu_page( 'woocommerce', 'Revisión de pagos', 'Revisión de pagos', 'manage_woocommerce', self::SLUG, array( $this, 'panel' ) );
	}

	public function panel() {
		$casos  = $this->casos();
		$filtro = sanitize_key( $_GET['v'] ?? 'abiertos' );
		$o      = $this->opts();
		$n = array( 'CONTACTAR' => 0, 'REVISAR' => 0, 'ESPERAR' => 0, 'ALTA' => 0, 'PERDIDA' => 0, 'DUPLICADA' => 0, 'SUSTITUIDA' => 0, 'RESUELTO' => 0, 'gestionados' => 0 );
		foreach ( $casos as $x ) { if ( $x['gestionado'] ) $n['gestionados']++; else $n[ $x['veredicto'] ]++; }
		$abiertos = $n['CONTACTAR'] + $n['REVISAR'] + $n['ESPERAR'];

		$lista = array_filter( $casos, function ( $x ) use ( $filtro ) {
			if ( $filtro === 'gestionados' ) return $x['gestionado'];
			if ( $x['gestionado'] ) return false;
			if ( $filtro === 'abiertos' ) return in_array( $x['veredicto'], array( 'CONTACTAR', 'REVISAR', 'ESPERAR' ), true );
			if ( $filtro === 'todos' ) return true;
			if ( $filtro === 'sustituida' ) return in_array( $x['veredicto'], array( 'SUSTITUIDA', 'DUPLICADA' ), true );
			return strtolower( $x['veredicto'] ) === $filtro;
		} );
		$prox = wp_next_scheduled( self::CRON_DIG );
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<style>
		.aup{--r:#ed4044;--bg:#f5f4f0;--t:#1a1a1a;--d:#292929;--g:#cfcfcf;font-family:"PP Neue Montreal Mono",ui-monospace,Menlo,Consolas,monospace;color:var(--t);margin:20px 20px 40px 0;font-size:13px}
		.aup *{box-sizing:border-box;border-radius:0!important;box-shadow:none!important}
		.aup h1{font-family:"PP Neue Machina",-apple-system,Helvetica,Arial,sans-serif;font-weight:300;font-style:italic;font-size:30px;letter-spacing:-.01em;margin:0 0 4px;color:var(--t)}
		.aup .sub{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--r);margin-bottom:22px}
		.aup .kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:1px;background:var(--g);border:1px solid var(--g);margin-bottom:24px}
		.aup .kpi{background:#fff;padding:14px 16px;text-decoration:none;color:var(--t);display:block}
		.aup .kpi.on{background:var(--t);color:#fff}
		.aup .kpi b{display:block;font-size:26px;font-weight:400;line-height:1;margin-bottom:6px;font-family:"PP Neue Machina",-apple-system,Helvetica,sans-serif}
		.aup .kpi span{font-size:10px;letter-spacing:.12em;text-transform:uppercase}
		.aup .kpi.c b{color:var(--r)}.aup .kpi.on.c b{color:#fff}
		.aup .caso{background:#fff;border-left:3px solid var(--d);margin-bottom:10px;padding:16px 18px;display:grid;grid-template-columns:1fr 340px;gap:24px}
		.aup .caso.CONTACTAR,.aup .caso.REVISAR{border-left-color:var(--r)}
		.aup .caso.PERDIDA{border-left-color:var(--g);opacity:.85}
		.aup .caso.RESUELTO,.aup .caso.SUSTITUIDA{border-left-color:#2d7a3a}
		.aup .caso.DUPLICADA{border-left-color:var(--g);opacity:.85}
		.aup .caso.gest{opacity:.6}
		.aup .badge{display:inline-block;padding:3px 8px;font-size:10px;letter-spacing:.1em;background:var(--d);color:#fff;vertical-align:middle;margin-right:8px}
		.aup .CONTACTAR .badge,.aup .REVISAR .badge{background:var(--r)}
		.aup .PERDIDA .badge{background:var(--g);color:var(--t)}
		.aup .RESUELTO .badge,.aup .SUSTITUIDA .badge{background:#2d7a3a}
		.aup .DUPLICADA .badge{background:var(--g);color:var(--t)}
		.aup .cli{font-size:15px}
		.aup .cli small{color:#666;font-size:12px;margin-left:8px}
		.aup .meta{color:#555;margin:8px 0 6px}
		.aup .mot{margin:6px 0}
		.aup .mot em{color:#888;font-style:normal}
		.aup .acc{background:var(--bg);padding:10px 12px;margin-top:10px;line-height:1.5}
		.aup .links{margin-top:10px;font-size:12px}
		.aup .links a{color:var(--r);text-decoration:none;margin-right:14px}
		.aup .lado{border-left:1px solid var(--g);padding-left:20px}
		.aup textarea{width:100%;font:12px/1.5 ui-monospace,Menlo,monospace;border:1px solid var(--g);padding:8px;background:#fff;color:var(--d);resize:vertical;min-height:130px}
		.aup .btn{display:inline-block;border:1px solid var(--t);background:#fff;color:var(--t);padding:7px 12px;font:11px/1 ui-monospace,Menlo,monospace;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;margin:8px 6px 0 0}
		.aup .btn.p{background:var(--t);color:#fff}
		.aup .btn.r{background:var(--r);border-color:var(--r);color:#fff}
		.aup .fila{display:flex;gap:6px;align-items:center}
		.aup input[type=text]{border:1px solid var(--g);padding:7px 8px;font:12px ui-monospace,Menlo,monospace;flex:1;background:#fff}
		.aup .aj{background:#fff;padding:18px;margin-top:30px;border-top:2px solid var(--t)}
		.aup .aj h2{font-size:11px;letter-spacing:.14em;text-transform:uppercase;margin:0 0 12px;font-weight:400}
		.aup .aj label{display:block;margin:6px 0;font-size:12px}
		.aup .aj input[type=email],.aup .aj input[type=time]{border:1px solid var(--g);padding:6px 8px;font:12px ui-monospace,Menlo,monospace}
		.aup .vacio{background:#fff;padding:40px;text-align:center;color:#666}
		.aup .ok{background:var(--t);color:#fff;padding:10px 14px;margin-bottom:16px;font-size:12px}
		.aup .gestbox{font-size:11px;color:#2d7a3a;margin-top:6px}
		@media (max-width:1100px){.aup .caso{grid-template-columns:1fr}.aup .lado{border:0;padding:0}.aup .kpis{grid-template-columns:repeat(3,1fr)}}
		</style>
		<div class="aup">
			<div class="sub">A Umbral · Revisión de pagos</div>
			<h1>Suscripciones en riesgo</h1>
			<p style="color:#555;margin:0 0 18px">Renovaciones fallidas en los últimos <?php echo self::VENTANA; ?> días, clasificadas por motivo de Stripe. Resumen semanal: <?php echo $prox ? 'lunes ' . esc_html( $this->f( $prox ) ) : 'no programado'; ?>.</p>
			<?php if ( ! empty( $_GET['ok'] ) ) echo '<div class="ok">' . ( $_GET['ok'] === 'digest' ? 'Resumen enviado a ' . esc_html( $o['email'] ) : 'Ajustes guardados' ) . '</div>'; ?>

			<div class="kpis">
				<?php
				$tabs = array( 'abiertos' => array( 'Abiertos', $abiertos, '' ), 'contactar' => array( 'Contactar', $n['CONTACTAR'], 'c' ), 'esperar' => array( 'Esperar', $n['ESPERAR'], '' ), 'alta' => array( 'Altas', $n['ALTA'], '' ), 'perdida' => array( 'Perdidas', $n['PERDIDA'], '' ), 'sustituida' => array( 'Re-alta', $n['SUSTITUIDA'] + $n['DUPLICADA'], '' ), 'resuelto' => array( 'Resueltas', $n['RESUELTO'], '' ), 'gestionados' => array( 'Gestionados', $n['gestionados'], '' ) );
				foreach ( $tabs as $k => $t ) printf( '<a class="kpi %s %s" href="%s"><b>%d</b><span>%s</span></a>', $t[2], $filtro === $k ? 'on' : '', esc_url( add_query_arg( 'v', $k, $base ) ), $t[1], $t[0] );
				?>
			</div>

			<?php if ( ! $lista ) : ?>
				<div class="vacio">Nada en esta vista.</div>
			<?php endif; ?>

			<?php foreach ( $lista as $x ) : ?>
			<div class="caso <?php echo esc_attr( $x['veredicto'] . ( $x['gestionado'] ? ' gest' : '' ) ); ?>">
				<div>
					<div class="cli"><span class="badge"><?php echo $x['veredicto']; ?></span><?php echo esc_html( $x['cliente'] ); ?><small><?php echo esc_html( $x['email'] ); ?></small></div>
					<div class="meta"><?php echo esc_html( $x['importe'] ); ?> &nbsp;·&nbsp; fallo <?php echo esc_html( $this->f( $x['fecha'], 'd/m/Y' ) ); ?> (hace <?php echo $x['dias']; ?> d) &nbsp;·&nbsp; reintentos <?php echo $x['reintentos']; ?><?php echo $x['pendientes'] ? ' · <b>siguiente ' . esc_html( $this->f( $x['proximo'], 'd/m H:i' ) ) . '</b>' : ( $x['cancelados'] ? ' · <b style="color:#ed4044">cancelados por Stripe</b>' : ' · <b>agotados</b>' ); ?> &nbsp;·&nbsp; sub <?php echo esc_html( $x['sub_status'] ); ?> / pedido <?php echo esc_html( $x['order_status'] ); ?></div>
					<div class="mot"><b><?php echo esc_html( $x['motivo_es'] ); ?></b><?php if ( $x['motivo_raw'] ) echo ' <em>— ' . esc_html( $x['motivo_raw'] ) . '</em>'; ?></div>
					<div class="acc"><?php echo esc_html( $x['accion'] ); ?></div>
					<div class="links">
						<a href="<?php echo esc_url( $x['url_admin'] ); ?>" target="_blank">Suscripción #<?php echo $x['sub_id']; ?></a>
						<a href="<?php echo esc_url( $x['url_pedido'] ); ?>" target="_blank">Pedido #<?php echo $x['order_id']; ?></a>
						<a href="mailto:<?php echo esc_attr( $x['email'] ); ?>?subject=<?php echo rawurlencode( 'Tu suscripción en A Umbral' ); ?>&body=<?php echo rawurlencode( $x['mensaje'] ); ?>">Abrir correo →</a>
					</div>
					<?php if ( $x['gestionado'] ) : ?><div class="gestbox">✓ Gestionado el <?php echo esc_html( $this->f( $x['gestion']['fecha'] ) ); ?> por <?php echo esc_html( $x['gestion']['por'] ); ?><?php echo $x['gestion']['nota'] ? ' — ' . esc_html( $x['gestion']['nota'] ) : ''; ?></div><?php endif; ?>
				</div>
				<div class="lado">
					<?php if ( in_array( $x['veredicto'], array( 'CONTACTAR', 'REVISAR', 'PERDIDA', 'ESPERAR' ), true ) ) : ?>
					<textarea readonly id="m<?php echo $x['sub_id']; ?>"><?php echo esc_textarea( $x['mensaje'] ); ?></textarea>
					<div class="fila"><input type="text" readonly value="<?php echo esc_attr( $x['url_pago'] ?: $x['url_cambio'] ); ?>" id="u<?php echo $x['sub_id']; ?>"><button class="btn" onclick="aupCopy('u<?php echo $x['sub_id']; ?>',this)">Copiar enlace</button></div>
					<button class="btn p" onclick="aupCopy('m<?php echo $x['sub_id']; ?>',this)">Copiar mensaje</button>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px">
						<?php wp_nonce_field( 'aup_pagos_gestionar' ); ?>
						<input type="hidden" name="action" value="aup_pagos_gestionar">
						<input type="hidden" name="sub_id" value="<?php echo $x['sub_id']; ?>">
						<input type="hidden" name="order_id" value="<?php echo $x['order_id']; ?>">
						<?php if ( $x['gestionado'] ) : ?>
							<input type="hidden" name="deshacer" value="1"><button class="btn">Reabrir</button>
						<?php else : ?>
							<div class="fila"><input type="text" name="nota" placeholder="nota (opcional): le escribí el 5/9…"><button class="btn r">Gestionado</button></div>
						<?php endif; ?>
					</form>
				</div>
			</div>
			<?php endforeach; ?>

			<div class="aj">
				<h2>Ajustes</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'aup_pagos_ajustes' ); ?>
					<input type="hidden" name="action" value="aup_pagos_ajustes">
					<label>Correo de avisos <input type="email" name="email" value="<?php echo esc_attr( $o['email'] ); ?>"></label>
					<label><input type="checkbox" name="alerta_inmediata" <?php checked( $o['alerta_inmediata'] ); ?>> Alerta inmediata (10 min tras el fallo) cuando el veredicto sea CONTACTAR</label>
					<label><input type="checkbox" name="silenciar_retry" <?php checked( $o['silenciar_retry'] ); ?>> Silenciar el correo de admin «Reintento de pago» de WooCommerce (lo sustituye el resumen)</label>
					<label><input type="checkbox" name="silenciar_failed" <?php checked( $o['silenciar_failed'] ); ?>> Silenciar el correo de admin «Pedido fallido» cuando el pedido es una <b>renovación</b> (la app ya te avisa; las altas nuevas fallidas siguen llegando)</label>
					<label>Hora del resumen de los lunes <input type="time" name="digest_hora" value="<?php echo esc_attr( $o['digest_hora'] ); ?>"></label>
					<button class="btn p">Guardar</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<?php wp_nonce_field( 'aup_pagos_digest_now' ); ?>
					<input type="hidden" name="action" value="aup_pagos_digest_now">
					<button class="btn">Enviar resumen ahora</button>
				</form>
			</div>
		</div>
		<script>
		function aupCopy(id,b){var el=document.getElementById(id);el.select();navigator.clipboard.writeText(el.value).then(function(){var t=b.textContent;b.textContent='Copiado';setTimeout(function(){b.textContent=t},1400)})}
		</script>
		<?php
	}


	/**
	 * Estado de suscripcion por email, para que otros modulos puedan cruzarlo.
	 * Devuelve email => array( estado, fin, sub_id, fallo ).
	 * Una sola consulta y cache en memoria: se llama una vez por pantalla.
	 */
	public function clientes() {
		static $cache = null;
		if ( null !== $cache ) return $cache;

		$guardado = get_transient( 'aup_clientes' );
		if ( is_array( $guardado ) ) return $cache = $guardado;

		global $wpdb;
		$filas = $wpdb->get_results(
			"SELECT p.ID, p.post_status,
				MAX(CASE WHEN m.meta_key='_billing_email' THEN m.meta_value END) AS email,
				MAX(CASE WHEN m.meta_key='_schedule_end'  THEN m.meta_value END) AS f_end
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE p.post_type = 'shop_subscription'
			 GROUP BY p.ID, p.post_status", ARRAY_A );

		$peso = array( 'wc-active' => 5, 'wc-pending-cancel' => 4, 'wc-on-hold' => 3, 'wc-pending' => 2, 'wc-cancelled' => 1, 'wc-expired' => 1 );
		$out  = array();
		foreach ( $filas as $f ) {
			$mail = strtolower( trim( (string) $f['email'] ) );
			if ( ! $mail ) continue;
			$pe = $peso[ $f['post_status'] ] ?? 0;
			// Si alguien tiene varias suscripciones, manda la de mejor estado.
			if ( isset( $out[ $mail ] ) && $out[ $mail ]['peso'] >= $pe ) continue;
			$out[ $mail ] = array(
				'estado' => str_replace( 'wc-', '', $f['post_status'] ),
				'fin'    => $f['f_end'] ? get_date_from_gmt( $f['f_end'], 'Y-m-d' ) : '',
				'sub_id' => (int) $f['ID'],
				'peso'   => $pe,
				'fallo'  => '',
			);
		}

		// Fallos de pago abiertos, del propio motor de casos.
		foreach ( $this->casos() as $c ) {
			$mail = strtolower( (string) $c['email'] );
			if ( ! $mail || $c['gestionado'] ) continue;
			if ( in_array( $c['veredicto'], array( 'CONTACTAR', 'REVISAR', 'ESPERAR', 'ALTA' ), true ) && isset( $out[ $mail ] ) ) {
				$out[ $mail ]['fallo'] = $c['veredicto'];
			}
		}

		set_transient( 'aup_clientes', $out, 5 * MINUTE_IN_SECONDS );
		return $cache = $out;
	}

	/** Resumen en una linea del estado de un cliente, o cadena vacia si no hay nada que decir. */
	public function aviso_cliente( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( ! $email ) return '';
		$c = $this->clientes();
		if ( ! isset( $c[ $email ] ) ) return 'No aparece ninguna suscripción con este correo.';

		$x = $c[ $email ];
		$f = $x['fin'] ? wp_date( 'j M', strtotime( $x['fin'] ) ) : '';

		if ( $x['estado'] === 'cancelled' )      return 'Su suscripción está cancelada' . ( $f ? ' (acceso hasta el ' . $f . ')' : '' ) . '.';
		if ( $x['estado'] === 'pending-cancel' ) return 'Ha pedido la baja: mantiene el acceso hasta el ' . $f . '.';
		if ( $x['estado'] === 'on-hold' )        return 'Su suscripción está en espera, sin pago al día.';
		if ( $x['estado'] === 'pending' )        return 'Su suscripción está pendiente de primer pago.';
		if ( $x['fallo'] === 'ALTA' )            return 'Nunca llegó a completar el primer pago.';
		if ( $x['fallo'] )                       return 'Tiene un pago fallido sin resolver.';
		return '';
	}


	/* ═══════════════ CLUB BCLB ═══════════════ */

	const BCLB_CUPONES = array( 'clubbclb', 'clubbclb2' );
	const BCLB_PARTE   = 0.50;  // porcentaje que corresponde al club
	const BCLB_IVA     = 0.21;

	/** Trimestre natural que contiene una fecha. Devuelve array( desde, hasta, etiqueta, clave ). */
	public function trimestre( $ref = null ) {
		$d   = new DateTime( $ref ?: current_time( 'Y-m-d' ), wp_timezone() );
		$q   = (int) ceil( (int) $d->format( 'n' ) / 3 );
		$a   = (int) $d->format( 'Y' );
		$ini = new DateTime( sprintf( '%d-%02d-01 00:00:00', $a, ( $q - 1 ) * 3 + 1 ), wp_timezone() );
		$fin = ( clone $ini )->modify( '+3 months' );
		$mes = array( 1 => 'ene-mar', 2 => 'abr-jun', 3 => 'jul-sep', 4 => 'oct-dic' );
		return array(
			'desde'  => $ini->format( 'Y-m-d H:i:s' ),
			'hasta'  => $fin->format( 'Y-m-d H:i:s' ),
			'etq'    => 'T' . $q . ' ' . $a . ' · ' . $mes[ $q ],
			'corto'  => 'T' . $q . ' ' . $a,
			'clave'  => $a . 'Q' . $q,
			'cerrado' => $fin->getTimestamp() <= current_time( 'timestamp' ),
		);
	}

	/** Lista de trimestres seleccionables: el actual y los anteriores. */
	public function trimestres( $cuantos = 4 ) {
		$out = array();
		$ref = new DateTime( current_time( 'Y-m-d' ), wp_timezone() );
		for ( $i = 0; $i < $cuantos; $i++ ) {
			$t = $this->trimestre( $ref->format( 'Y-m-d' ) );
			$out[ $t['clave'] ] = $t;
			$ref = new DateTime( $t['desde'], wp_timezone() );
			$ref->modify( '-1 day' );
		}
		return $out;
	}

	/**
	 * Pagos válidos de suscriptores del Club BCLB en un periodo, con el reparto calculado.
	 * Regla: del importe cobrado se descuenta la comisión de Stripe, la mitad de lo que queda
	 * corresponde al club, y esa mitad se desglosa en base imponible más IVA.
	 */
	public function bclb( $desde, $hasta ) {
		global $wpdb;
		$cup = "'" . implode( "','", array_map( 'esc_sql', self::BCLB_CUPONES ) ) . "'";

		// Quien pertenece al club lo dice su SUSCRIPCION, no cada pedido: los prorrateos y
		// algunos cobros sin descuento no copian el cupon.
		// La factura del club cubre solo las cuotas periodicas NO anuales.
		$todas = $wpdb->get_results(
			"SELECT p.ID, p.post_parent,
			   MAX(CASE WHEN m.meta_key='_billing_period' THEN m.meta_value END) AS periodo
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->prefix}woocommerce_order_items i
			   ON i.order_id = p.ID AND i.order_item_type = 'coupon' AND i.order_item_name IN ($cup)
			 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE p.post_type = 'shop_subscription'
			 GROUP BY p.ID, p.post_parent", ARRAY_A );

		$subs = array();   // suscripciones del club que facturan al club (no anuales)
		$anu  = array();   // suscripciones anuales, fuera del reparto
		$anuP = array();   // sus pedidos iniciales, que si llevan el cupon
		foreach ( $todas as $x ) {
			if ( $x['periodo'] === 'year' ) {
				$anu[] = (int) $x['ID'];
				if ( $x['post_parent'] ) $anuP[] = (int) $x['post_parent'];
			} else {
				$subs[] = (int) $x['ID'];
			}
		}
		$in   = $subs ? implode( ',', $subs ) : '0';
		$inA  = $anu  ? implode( ',', $anu )  : '0';
		$inAP = $anuP ? implode( ',', $anuP ) : '0';

		$filas = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_date, p.post_status,
				MAX(CASE WHEN m.meta_key='_order_total'        THEN m.meta_value END) AS total,
				MAX(CASE WHEN m.meta_key='_stripe_fee'         THEN m.meta_value END) AS fee,
				MAX(CASE WHEN m.meta_key='_billing_email'      THEN m.meta_value END) AS email,
				MAX(CASE WHEN m.meta_key='_billing_first_name' THEN m.meta_value END) AS nombre,
				MAX(CASE WHEN m.meta_key='_billing_last_name'  THEN m.meta_value END) AS apellidos,
				EXISTS(SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items c
				       WHERE c.order_id = p.ID AND c.order_item_type='coupon' AND c.order_item_name IN ($cup)) AS con_cupon
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ('wc-completed','wc-processing')
			   AND p.post_date >= %s AND p.post_date < %s
			   AND (
			     p.ID IN (SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items
			              WHERE order_item_type='coupon' AND order_item_name IN ($cup))
			     OR p.ID IN (SELECT post_id FROM {$wpdb->postmeta}
			                 WHERE meta_key IN ('_subscription_renewal','_subscription_switch','_subscription_resubscribe')
			                   AND meta_value IN ($in))
			   )
			   AND p.ID NOT IN ($inAP)
			   AND p.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta}
			                    WHERE meta_key IN ('_subscription_renewal','_subscription_switch','_subscription_resubscribe')
			                      AND meta_value IN ($inA))
			 GROUP BY p.ID, p.post_date, p.post_status
			 ORDER BY p.post_date ASC", $desde, $hasta
		), ARRAY_A );

		$pagos = array();
		$r = array(
			'n' => 0, 'cobrado' => 0.0, 'fees' => 0.0, 'neto' => 0.0,
			'club' => 0.0, 'base' => 0.0, 'iva' => 0.0,
			'sin_fee' => 0, 'sin_cupon' => 0, 'personas' => array(),
		);

		foreach ( $filas as $f ) {
			$total = (float) $f['total'];
			$tiene = $f['fee'] !== null && $f['fee'] !== '';
			$fee   = $tiene ? (float) $f['fee'] : 0.0;
			$neto  = $total - $fee;
			$club  = $neto * self::BCLB_PARTE;
			$base  = $club / ( 1 + self::BCLB_IVA );
			$iva   = $club - $base;
			$mail  = strtolower( (string) $f['email'] );

			$pagos[] = array(
				'id'      => (int) $f['ID'],
				'fecha'   => substr( $f['post_date'], 0, 10 ),
				'cliente' => trim( $f['nombre'] . ' ' . $f['apellidos'] ) ?: $mail,
				'email'   => $mail,
				'total'   => $total,
				'fee'     => $fee,
				'sin_fee' => ! $tiene,
				'sin_cupon' => empty( $f['con_cupon'] ),
				'neto'    => $neto,
				'club'    => $club,
				'base'    => $base,
				'iva'     => $iva,
			);

			$r['n']++;
			$r['cobrado'] += $total;
			$r['fees']    += $fee;
			$r['neto']    += $neto;
			$r['club']    += $club;
			$r['base']    += $base;
			$r['iva']     += $iva;
			if ( ! $tiene ) $r['sin_fee']++;
			if ( empty( $f['con_cupon'] ) ) $r['sin_cupon']++;
			if ( $mail ) $r['personas'][ $mail ] = true;
		}

		$r['suscriptores'] = count( $r['personas'] );
		unset( $r['personas'] );
		$r['pagos'] = $pagos;
		return $r;
	}

	/* ═══════════════ BAJAS VOLUNTARIAS ═══════════════ */

	const META_BAJA = '_aumbral_tp_baja';

	/**
	 * Suscripciones canceladas por el propio cliente.
	 * La fecha que importa no es la de la cancelacion sino la de fin del periodo ya pagado
	 * (_schedule_end): hasta ese dia el acceso sigue vivo y no hay que tocar TrainingPeaks.
	 * WooCommerce guarda esas fechas en UTC, asi que se convierten a hora local.
	 */
	public function bajas( $dias = 60 ) {
		static $cache = array();
		if ( isset( $cache[ $dias ] ) ) return $cache[ $dias ];

		global $wpdb;
		$desde = gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) $dias . ' days' ) );

		$filas = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_status,
				MAX(CASE WHEN m.meta_key='_schedule_cancelled'  THEN m.meta_value END) AS f_cancel,
				MAX(CASE WHEN m.meta_key='_schedule_end'        THEN m.meta_value END) AS f_end,
				MAX(CASE WHEN m.meta_key='_billing_first_name'  THEN m.meta_value END) AS nombre,
				MAX(CASE WHEN m.meta_key='_billing_last_name'   THEN m.meta_value END) AS apellidos,
				MAX(CASE WHEN m.meta_key='_billing_email'       THEN m.meta_value END) AS email,
				MAX(CASE WHEN m.meta_key='_customer_user'       THEN m.meta_value END) AS user_id,
				MAX(CASE WHEN m.meta_key=%s                     THEN m.meta_value END) AS tp_baja
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			 WHERE p.post_type = 'shop_subscription'
			   AND p.post_status IN ('wc-pending-cancel','wc-cancelled')
			 GROUP BY p.ID, p.post_status
			 HAVING f_cancel >= %s OR p.post_status = 'wc-pending-cancel'
			 ORDER BY f_end ASC",
			self::META_BAJA, $desde
		), ARRAY_A );

		$hoy  = current_time( 'Y-m-d' );
		$out  = array();
		foreach ( $filas as $f ) {
			$fin   = $f['f_end']    ? get_date_from_gmt( $f['f_end'], 'Y-m-d' )       : '';
			$baja  = $f['f_cancel'] ? get_date_from_gmt( $f['f_cancel'], 'Y-m-d' )    : '';
			$out[] = array(
				'id'        => (int) $f['ID'],
				'cliente'   => trim( $f['nombre'] . ' ' . $f['apellidos'] ),
				'email'     => strtolower( (string) $f['email'] ),
				'user_id'   => (int) $f['user_id'],
				'baja'      => $baja,
				'baja_hora' => $f['f_cancel'] ? get_date_from_gmt( $f['f_cancel'], 'H:i' ) : '',
				'fin'       => $fin,
				'vence_ya'  => $fin && $fin <= $hoy,
				'dias'      => $fin ? (int) floor( ( strtotime( $fin ) - strtotime( $hoy ) ) / DAY_IN_SECONDS ) : 0,
				'activa'    => $f['post_status'] === 'wc-pending-cancel',
				'hecho'     => ! empty( $f['tp_baja'] ),
				'hecho_el'  => $f['tp_baja'] ? (int) $f['tp_baja'] : 0,
				'url_admin' => admin_url( 'post.php?post=' . (int) $f['ID'] . '&action=edit' ),
			);
		}
		return $cache[ $dias ] = $out;
	}

	/** Marca (o desmarca) que ya se ha quitado del plan en TrainingPeaks. */
	public function baja_hecha() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permiso' );
		check_admin_referer( 'aup_baja_hecha' );
		$id = absint( $_POST['sub_id'] ?? 0 );
		delete_transient( 'aup_clientes' );
		if ( $id && get_post_type( $id ) === 'shop_subscription' ) {
			if ( ! empty( $_POST['deshacer'] ) ) {
				delete_post_meta( $id, self::META_BAJA );
			} else {
				update_post_meta( $id, self::META_BAJA, time() );
				update_post_meta( $id, '_aumbral_tp_baja_por', get_current_user_id() );
			}
		}
		wp_safe_redirect( wp_get_referer() ?: home_url( '/app/pagos/?v=bajas' ) );
		exit;
	}
}

require_once __DIR__ . '/app.php';

add_action( 'admin_post_aup_baja_hecha', function () { AUP_Pagos::i()->baja_hecha(); } );

add_action( 'plugins_loaded', function () {
	if ( class_exists( 'WC_Subscriptions' ) || function_exists( 'wcs_get_subscription' ) ) { AUP_Pagos::i(); AUP_Pagos_App::i(); }
}, 20 );

register_activation_hook( __FILE__, function () {
	$h  = '08:00';
	$dt = new DateTime( 'next monday ' . $h, wp_timezone() );
	if ( ! wp_next_scheduled( 'aup_pagos_digest' ) ) wp_schedule_event( $dt->getTimestamp(), 'weekly', 'aup_pagos_digest' );
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'aup_pagos_digest' );
} );
