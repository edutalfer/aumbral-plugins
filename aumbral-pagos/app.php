<?php
/**
 * A Umbral · Revisión de pagos — módulo «pagos» de la app interna (/app/pagos/).
 * Solo capa de presentación: toda la lógica vive en AUP_Pagos::casos().
 * El contenedor (cabecera, navegación, PWA, estilos) lo aporta el plugin «A Umbral · App interna».
 * Si ese plugin no está activo, /pagos/ redirige al panel clásico de wp-admin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class AUP_Pagos_App {

	const SLUG = 'pagos';
	private static $inst;
	public static function i() { return self::$inst ?: ( self::$inst = new self() ); }

	private function __construct() {
		add_filter( 'aumbral_app_modulos', array( $this, 'registrar' ) );
		add_action( 'template_redirect', array( $this, 'ruta_antigua' ), 0 );
	}

	/* ───────────── Registro en la app ───────────── */

	public function registrar( $m ) {
		$m[ self::SLUG ] = array(
			'nav'    => 'Pagos',
			'titulo' => 'Pagos',
			'orden'  => 10,
			'cap'    => 'manage_woocommerce',
			'badge'  => array( $this, 'badge' ),
			'render' => array( $this, 'cuerpo' ),
		);
		return $m;
	}

	/** Nº de casos abiertos sin gestionar, para el punto rojo de la navegación. */
	public function badge() {
		$n = 0;
		foreach ( AUP_Pagos::i()->casos() as $x ) {
			if ( ! $x['gestionado'] && in_array( $x['veredicto'], array( 'CONTACTAR', 'REVISAR', 'ESPERAR', 'ALTA' ), true ) ) $n++;
		}
		return $n;
	}

	/** /pagos/ y sus subrutas antiguas → /app/pagos/ (marcadores y PWA ya instalada). */
	public function ruta_antigua() {
		$p = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
		if ( $p !== self::SLUG && strpos( $p, self::SLUG . '/' ) !== 0 ) return;

		$destino = class_exists( 'AUmbral_App' )
			? AUmbral_App::url( self::SLUG )
			: admin_url( 'admin.php?page=' . AUP_Pagos::SLUG );

		wp_safe_redirect( $destino, 302 );
		exit;
	}

	/* ───────────── Cuerpo de la pantalla ───────────── */

	public function cuerpo( $ctx ) {
		$base   = $ctx['base'];
		$P      = AUP_Pagos::i();
		$casos  = $P->casos();
		$filtro = sanitize_key( $ctx['query']['v'] ?? 'abiertos' );

		$n = array( 'CONTACTAR' => 0, 'REVISAR' => 0, 'ESPERAR' => 0, 'ALTA' => 0, 'PERDIDA' => 0, 'DUPLICADA' => 0, 'SUSTITUIDA' => 0, 'RESUELTO' => 0, 'gestionados' => 0 );
		$eu = array( 'CONTACTAR' => 0, 'REVISAR' => 0, 'ESPERAR' => 0, 'ALTA' => 0 );
		foreach ( $casos as $x ) {
			if ( $x['gestionado'] ) { $n['gestionados']++; continue; }
			$n[ $x['veredicto'] ]++;
			if ( isset( $eu[ $x['veredicto'] ] ) ) {
				$eu[ $x['veredicto'] ] += (float) str_replace( ',', '.', preg_replace( '/[^0-9,]/', '', explode( '/', $x['importe'] )[0] ) );
			}
		}
		$abiertos = $n['CONTACTAR'] + $n['REVISAR'] + $n['ESPERAR'] + $n['ALTA'];
		$total    = array_sum( $eu );
		$urg      = $eu['CONTACTAR'] + $eu['REVISAR'] + $eu['ALTA'];
		$pUrg     = $total > 0 ? round( $urg / $total * 100 ) : 0;
		$pEsp     = 100 - $pUrg;

		$lista = array_filter( $casos, function ( $x ) use ( $filtro ) {
			if ( $filtro === 'gestionados' ) return $x['gestionado'];
			if ( $x['gestionado'] ) return false;
			if ( $filtro === 'abiertos' )   return in_array( $x['veredicto'], array( 'CONTACTAR', 'REVISAR', 'ESPERAR', 'ALTA' ), true );
			if ( $filtro === 'alta' )       return $x['veredicto'] === 'ALTA';
			if ( $filtro === 'sustituida' ) return in_array( $x['veredicto'], array( 'SUSTITUIDA', 'DUPLICADA' ), true );
			if ( $filtro === 'archivo' )    return in_array( $x['veredicto'], array( 'PERDIDA', 'RESUELTO', 'SUSTITUIDA', 'DUPLICADA' ), true );
			if ( $filtro === 'todos' )      return true;
			return strtolower( $x['veredicto'] ) === $filtro;
		} );

		$cifra = number_format( $total, 2, ',', '.' );
		$part  = explode( ',', $cifra );
		$etq   = array( 'CONTACTAR' => 'Escríbele', 'REVISAR' => 'Revisar', 'ESPERAR' => 'En espera', 'ALTA' => 'Nunca pagó', 'PERDIDA' => 'Perdida', 'DUPLICADA' => 'Duplicada', 'SUSTITUIDA' => 'Se reactivó', 'RESUELTO' => 'Cobrada' );
		?>
 <div class="card">
 <?php if ( $abiertos ) : ?>
  <div class="lbl">En riesgo ahora mismo</div>
  <div class="big"><?php echo esc_html( $part[0] ); ?><sup><?php echo esc_html( $part[1] ); ?> &euro;</sup></div>
  <div class="bar">
   <i style="width:<?php echo (int) $pUrg; ?>%;background:var(--r)"></i>
   <i style="width:<?php echo (int) $pEsp; ?>%;background:var(--am)"></i>
  </div>
  <div class="leg">
   <div><span class="dot" style="background:var(--r)"></span><b><?php echo (int) ( $n['CONTACTAR'] + $n['REVISAR'] ); ?></b> necesitan un mensaje</div>
   <div><span class="dot" style="background:var(--am)"></span><b><?php echo (int) $n['ESPERAR']; ?></b> se reintentan solas</div>
   <?php if ( $n['ALTA'] ) : ?>
   <div><span class="dot" style="background:#8a6d3b"></span><b><?php echo (int) $n['ALTA']; ?></b> nunca llegaron a pagar</div>
   <?php endif; ?>
  </div>
 <?php else : ?>
  <div class="okh"><span class="em">&#127881;</span><div>
   <div style="font-size:17px;font-weight:600">Todo al día</div>
   <div class="lbl" style="margin-top:3px">Ninguna renovación pendiente de gestión</div>
  </div></div>
 <?php endif; ?>
 </div>

 <div class="chips">
 <?php
	$chips = array(
		'abiertos'    => array( 'Pendientes', $abiertos ),
		'contactar'   => array( 'Escribir', $n['CONTACTAR'] ),
		'esperar'     => array( 'En espera', $n['ESPERAR'] ),
		'alta'        => array( 'Nunca pagó', $n['ALTA'] ),
		'archivo'     => array( 'Archivo', 0 ),
		'gestionados' => array( 'Hechos', 0 ),
		'todos'       => array( 'Todo', 0 ),
	);
	foreach ( $chips as $k => $c ) {
		printf( '<a class="%s" href="%s">%s%s</a>',
			$filtro === $k ? 'on' : '',
			esc_url( add_query_arg( 'v', $k, $base ) ),
			esc_html( $c[0] ),
			$c[1] ? ' <b>' . (int) $c[1] . '</b>' : '' );
	}
 ?>
 </div>

 <?php
 $titulos = array(
	'abiertos'    => array( 'Pendientes', 'lo que sigue abierto' ),
	'contactar'   => array( 'Escribir hoy', 'no se arreglan solas' ),
	'esperar'     => array( 'En espera', 'Stripe lo reintenta' ),
	'alta'        => array( 'Nunca llegaron a pagar', 'altas sin primer pago' ),
	'archivo'     => array( 'Archivo', 'cerrado o irrelevante' ),
	'gestionados' => array( 'Gestionados', 'ya te ocupaste' ),
	'perdida'     => array( 'Perdidas', 'canceladas tras el fallo' ),
	'sustituida'  => array( 'Re-altas', 'volvió a suscribirse' ),
	'resuelto'    => array( 'Cobradas', 'un reintento funcionó' ),
	'todos'       => array( 'Todo', 'sin filtrar' ),
 );
 $t = isset( $titulos[ $filtro ] ) ? $titulos[ $filtro ] : $titulos['abiertos'];
 ?>
 <h2><?php echo esc_html( $t[0] ); ?> <span><?php echo count( $lista ) . ' · ' . esc_html( $t[1] ); ?></span></h2>

 <?php if ( ! $lista ) : ?>
  <div class="zero"><span class="em">&#9749;</span><b>Nada por aquí</b><p>No hay casos en esta vista.</p></div>
 <?php endif; ?>

 <?php foreach ( $lista as $x ) :
	$msg = in_array( $x['veredicto'], array( 'CONTACTAR', 'REVISAR', 'ESPERAR', 'ALTA', 'PERDIDA' ), true );
	$url = $x['url_pago'] ? $x['url_pago'] : $x['url_cambio'];
 ?>
 <article class="caso <?php echo esc_attr( $x['veredicto'] . ( $x['gestionado'] ? ' q' : '' ) ); ?>">
  <div class="f1">
   <span class="tag"><?php echo esc_html( $etq[ $x['veredicto'] ] ); ?></span>
   <span class="hace"><?php echo (int) $x['dias']; ?> días</span>
  </div>
  <div class="nom"><?php echo esc_html( $x['cliente'] ); ?></div>
  <div class="sub"><?php echo esc_html( $x['email'] ); ?></div>
  <div class="meta">
   <span class="pi"><b><?php echo esc_html( $x['importe'] ); ?></b></span>
   <span class="pi"><?php echo (int) $x['reintentos']; ?> reintentos</span>
   <?php if ( $x['pendientes'] ) : ?>
    <span class="pi">siguiente <b><?php echo esc_html( wp_date( 'd/m', $x['proximo'] ) ); ?></b></span>
   <?php elseif ( $x['cancelados'] ) : ?>
    <span class="pi al">Stripe ya no reintenta</span>
   <?php else : ?>
    <span class="pi">agotados</span>
   <?php endif; ?>
  </div>
  <div class="mot"><?php echo esc_html( $x['motivo_es'] ); ?>
   <?php if ( $x['motivo_raw'] ) : ?><div class="raw"><?php echo esc_html( $x['motivo_raw'] ); ?></div><?php endif; ?>
  </div>
  <div class="acc"><?php echo esc_html( $x['accion'] ); ?></div>

  <?php if ( $msg ) : ?>
  <details>
   <summary>Ver el mensaje que le mandarías</summary>
   <div class="msg" id="m<?php echo (int) $x['sub_id']; ?>"><?php echo esc_html( $x['mensaje'] ); ?></div>
   <div class="lnk" id="u<?php echo (int) $x['sub_id']; ?>"><?php echo esc_html( $url ); ?></div>
  </details>
  <?php endif; ?>

  <div class="bts">
   <?php if ( $msg ) : ?>
    <button class="b p" onclick="cp('m<?php echo (int) $x['sub_id']; ?>')">Copiar mensaje</button>
    <button class="b" onclick="cp('u<?php echo (int) $x['sub_id']; ?>')">Copiar enlace</button>
    <a class="b" target="_blank" rel="noopener" href="https://mail.google.com/mail/?view=cm&fs=1&tf=1&to=<?php echo rawurlencode( $x['email'] ); ?>&su=<?php echo rawurlencode( 'Tu suscripción en A Umbral' ); ?>&body=<?php echo rawurlencode( $x['mensaje'] ); ?>">Gmail</a>
   <?php endif; ?>
   <a class="b o" href="<?php echo esc_url( $x['url_admin'] ); ?>" target="_blank" rel="noopener">Ficha</a>
  </div>

  <?php if ( $x['gestionado'] ) : ?>
   <div class="hecho">&#10003;<span>Gestionado el <?php echo esc_html( wp_date( 'd/m/Y', $x['gestion']['fecha'] ) ); ?><?php echo $x['gestion']['nota'] ? ' — ' . esc_html( $x['gestion']['nota'] ) : ''; ?></span></div>
  <?php endif; ?>

  <form class="nf" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
   <?php wp_nonce_field( 'aup_pagos_gestionar' ); ?>
   <input type="hidden" name="action" value="aup_pagos_gestionar">
   <input type="hidden" name="sub_id" value="<?php echo (int) $x['sub_id']; ?>">
   <input type="hidden" name="order_id" value="<?php echo (int) $x['order_id']; ?>">
   <?php if ( $x['gestionado'] ) : ?>
    <input type="hidden" name="deshacer" value="1"><button class="b o">Reabrir</button>
   <?php else : ?>
    <input type="text" name="nota" placeholder="nota rápida…"><button class="b">Hecho</button>
   <?php endif; ?>
  </form>
 </article>
 <?php endforeach;
	}
}
