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
		add_filter( 'aumbral_app_resumen', array( $this, 'resumen' ) );
		add_action( 'admin_notices', array( $this, 'aviso_panel' ) );
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
		return $n + $this->por_quitar() + count( $this->insistir() );
	}

	/** Días que se dejan pasar antes de volver a sacar un caso ya gestionado. */
	const DIAS_INSISTIR = 5;

	/**
	 * Casos marcados como hechos que siguen sin resolverse.
	 * Marcar «Hecho» significa «le he escrito», no «ha pagado»: si pasados unos días
	 * la suscripción sigue sin estar al día, el caso vuelve a la superficie.
	 */
	public function insistir() {
		$out = array();
		$cli = AUP_Pagos::i()->clientes();

		foreach ( AUP_Pagos::i()->casos() as $x ) {
			if ( ! $x['gestionado'] || empty( $x['gestion']['fecha'] ) ) continue;
			// Si ya canceló o se resolvió, no hay nada que insistir.
			if ( ! in_array( $x['veredicto'], array( 'CONTACTAR', 'REVISAR', 'ALTA', 'ESPERAR' ), true ) ) continue;

			$mail = strtolower( (string) $x['email'] );
			$est  = $cli[ $mail ]['estado'] ?? '';
			if ( $est === 'active' ) continue; // volvió a estar al día: resuelto

			$dias = (int) floor( ( current_time( 'timestamp' ) - (int) $x['gestion']['fecha'] ) / DAY_IN_SECONDS );
			if ( $dias < self::DIAS_INSISTIR ) continue;

			$x['dias_desde'] = $dias;
			$out[] = $x;
		}

		usort( $out, function ( $a, $b ) { return $b['dias_desde'] <=> $a['dias_desde']; } );
		return $out;
	}

	/** Bajas cuyo periodo pagado ya ha vencido y siguen en TrainingPeaks. */
	public function por_quitar() {
		$n = 0;
		foreach ( AUP_Pagos::i()->bajas() as $b ) if ( $b['vence_ya'] && ! $b['hecho'] ) $n++;
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


	/** El panel de wp-admin sigue existiendo como respaldo, pero el sitio de trabajo es la app. */
	public function aviso_panel() {
		if ( ( $_GET['page'] ?? '' ) !== AUP_Pagos::SLUG ) return;
		printf(
			'<div class="notice notice-info"><p><strong>Este panel es el respaldo.</strong> ' .
			'El día a día se lleva en <a href="%s">la app</a>, que además tiene bajas, planes y tareas. ' .
			'Esta pantalla se mantiene por si la app falla.</p></div>',
			esc_url( aumbral_app_url( self::SLUG ) )
		);
	}

	/** Bloque del resumen diario: quien necesita un mensaje y a quien hay que quitar de TP. */
	public function resumen( $bloques ) {
		$items = array();
		$etq   = array( 'CONTACTAR' => 'necesita que le escribas', 'REVISAR' => 'hay que revisarlo', 'ALTA' => 'nunca completó el primer pago' );

		foreach ( AUP_Pagos::i()->casos() as $x ) {
			if ( $x['gestionado'] || ! isset( $etq[ $x['veredicto'] ] ) ) continue;
			$items[] = array(
				'texto'   => $x['cliente'] . ': ' . $etq[ $x['veredicto'] ],
				'detalle' => $x['importe'] . ' · ' . $x['motivo_es'],
				'urgente' => $x['veredicto'] !== 'ALTA',
			);
		}

		foreach ( $this->insistir() as $x ) {
			$items[] = array(
				'texto'   => $x['cliente'] . ': sigue sin pagar',
				'detalle' => 'Le escribiste hace ' . $x['dias_desde'] . ' días. ' . $x['importe'] . '.',
				'urgente' => true,
			);
		}

		foreach ( AUP_Pagos::i()->bajas() as $b ) {
			if ( $b['hecho'] || ! $b['vence_ya'] ) continue;
			$items[] = array(
				'texto'   => $b['cliente'] . ': quitar de TrainingPeaks',
				'detalle' => $b['dias'] === 0 ? 'Su acceso termina hoy.' : 'Su acceso terminó el ' . wp_date( 'j M', strtotime( $b['fin'] ) ) . '.',
				'urgente' => true,
			);
		}

		if ( $items ) {
			$bloques[] = array(
				'titulo' => 'Pagos y bajas',
				'url'    => aumbral_app_url( self::SLUG ),
				'items'  => $items,
			);
		}
		return $bloques;
	}


	/* ───────────── Club BCLB ───────────── */

	public function cuerpo_bclb( $ctx ) {
		$base_url = $ctx['base'];
		$P        = AUP_Pagos::i();
		$trims    = $P->trimestres( 4 );
		$clave    = sanitize_text_field( $ctx['query']['t'] ?? '' );
		$t        = $trims[ $clave ] ?? reset( $trims );
		$r        = $P->bclb( $t['desde'], $t['hasta'] );

		$eur = function ( $n ) { return number_format( (float) $n, 2, ',', '.' ) . ' €'; };

		$cifra = explode( ',', number_format( $r['base'], 2, ',', '.' ) );
		?>
 <div class="card">
  <div class="lbl">Base imponible de la factura · <?php echo esc_html( $t['etq'] ); ?></div>
  <div class="big"><?php echo esc_html( $cifra[0] ); ?><sup><?php echo esc_html( $cifra[1] ); ?> &euro;</sup></div>
  <div class="leg" style="margin-top:16px">
   <div><span class="dot" style="background:var(--r)"></span>IVA 21% <b><?php echo esc_html( $eur( $r['iva'] ) ); ?></b></div>
   <div><span class="dot" style="background:var(--ink)"></span>Total <b><?php echo esc_html( $eur( $r['club'] ) ); ?></b></div>
  </div>
  <div class="meta">
   <span class="pi"><b><?php echo (int) $r['n']; ?></b> pagos cobrados</span>
   <span class="pi"><b><?php echo (int) $r['suscriptores']; ?></b> suscriptores</span>
   <span class="pi">cobrado <b><?php echo esc_html( $eur( $r['cobrado'] ) ); ?></b></span>
   <span class="pi">Stripe <b><?php echo esc_html( $eur( $r['fees'] ) ); ?></b></span>
  </div>
  <div class="acc" style="margin-top:16px;white-space:pre-wrap" id="bclbtxt">Club BCLB · <?php echo esc_html( $t['etq'] ); ?>
Pagos: <?php echo (int) $r['n']; ?> de <?php echo (int) $r['suscriptores']; ?> suscriptores
Cobrado: <?php echo esc_html( $eur( $r['cobrado'] ) ); ?>
Comisiones de Stripe: <?php echo esc_html( $eur( $r['fees'] ) ); ?>
Neto: <?php echo esc_html( $eur( $r['neto'] ) ); ?>
50% del club: <?php echo esc_html( $eur( $r['club'] ) ); ?>
Base imponible: <?php echo esc_html( $eur( $r['base'] ) ); ?>
IVA 21%: <?php echo esc_html( $eur( $r['iva'] ) ); ?>
Total factura: <?php echo esc_html( $eur( $r['club'] ) ); ?></div>
  <div class="bts">
   <button class="b p" onclick="cp('bclbtxt')">Copiar para la factura</button>
   <?php if ( ! $t['cerrado'] ) : ?><span class="pi al">trimestre en curso</span><?php endif; ?>
  </div>
  <?php if ( $r['sin_cupon'] ) : ?>
   <div class="acc" style="background:var(--azs);color:var(--az);margin-top:10px">
    <?php echo (int) $r['sin_cupon']; ?> pago(s) sin el cupón en el pedido, contados porque su suscripción sí es del club:
    prorrateos por cambio de plan o cobros en los que el descuento no llegó a aplicarse.
   </div>
  <?php endif; ?>

  <?php if ( $r['sin_fee'] ) : ?>
   <div class="acc" style="background:var(--ams);color:var(--am);margin-top:10px">
    <?php echo (int) $r['sin_fee']; ?> pago(s) sin comisión de Stripe guardada: se han contado con comisión cero, así que el 50% sale algo alto en esos.
   </div>
  <?php endif; ?>
 </div>

 <div class="caso" style="margin-top:14px">
  <div class="f1"><span class="tag gy">Aviso por correo</span></div>
  <div class="acc" style="margin-top:12px">
   El día 1 de enero, abril, julio y octubre sale un correo con la base imponible, el IVA y el total
   del trimestre recién cerrado.
   <?php $env = get_option( 'aup_bclb_enviado' ); ?>
   <?php if ( $env ) : ?><br>Último enviado: <b><?php echo esc_html( $env ); ?></b>.<?php endif; ?>
  </div>
  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
   <?php wp_nonce_field( 'aup_bclb' ); ?>
   <input type="hidden" name="action" value="aup_bclb">
   <input type="hidden" name="t" value="<?php echo esc_attr( $t['clave'] ); ?>">
   <div class="nf">
    <input type="email" name="email" placeholder="correo…" value="<?php echo esc_attr( $P->bclb_destinatario() ); ?>">
    <button class="b">Guardar</button>
   </div>
   <div class="nf"><button class="b o" name="enviar" value="1">Enviarme ahora el de <?php echo esc_html( $t['corto'] ); ?></button></div>
  </form>
 </div>

 <div class="chips">
  <a href="<?php echo esc_url( add_query_arg( 'v', 'abiertos', $base_url ) ); ?>">&larr; Pagos</a>
 <?php foreach ( $trims as $k => $x ) {
	printf( '<a class="%s" href="%s">%s</a>',
		$k === $t['clave'] ? 'on' : '',
		esc_url( add_query_arg( array( 'v' => 'bclb', 't' => $k ), $base_url ) ),
		esc_html( $x['corto'] ) );
 } ?>
 </div>

 <?php
	}

	/* ───────────── Bajas voluntarias ───────────── */

	public function cuerpo_bajas( $ctx ) {
		$base   = $ctx['base'];
		$v      = sanitize_key( $ctx['query']['v'] ?? 'bajas' );
		$hoy    = current_time( 'Y-m-d' );
		$todas  = AUP_Pagos::i()->bajas();

		$hoy_n = $prog = $quitar = 0;
		foreach ( $todas as $b ) {
			if ( $b['baja'] === $hoy ) $hoy_n++;
			if ( $b['hecho'] ) continue;
			if ( $b['vence_ya'] ) $quitar++; else $prog++;
		}

		$lista = array_filter( $todas, function ( $b ) use ( $v, $hoy ) {
			if ( $v === 'bajas_hechas' ) return $b['hecho'];
			if ( $b['hecho'] ) return false;
			if ( $v === 'bajas_prog' )   return ! $b['vence_ya'];
			if ( $v === 'bajas_hoy' )    return $b['baja'] === $hoy;
			return $b['vence_ya'];
		} );
		?>
 <div class="card">
 <?php if ( $quitar ) : ?>
  <div class="lbl">Por quitar de TrainingPeaks</div>
  <div class="big"><?php echo (int) $quitar; ?></div>
  <div class="leg" style="margin-top:14px">
   <div><span class="dot" style="background:var(--r)"></span><b><?php echo (int) $quitar; ?></b> ya sin acceso</div>
   <div><span class="dot" style="background:var(--am)"></span><b><?php echo (int) $prog; ?></b> con acceso hasta su fecha</div>
   <div><span class="dot" style="background:var(--mut)"></span><b><?php echo (int) $hoy_n; ?></b> se dieron de baja hoy</div>
  </div>
 <?php else : ?>
  <div class="okh"><span class="em">&#128076;</span><div>
   <div style="font-size:17px;font-weight:600">Nadie por quitar</div>
   <div class="lbl" style="margin-top:3px"><?php echo (int) $prog; ?> con el acceso aún vivo · <?php echo (int) $hoy_n; ?> bajas hoy</div>
  </div></div>
 <?php endif; ?>
 </div>

 <div class="chips">
  <a href="<?php echo esc_url( add_query_arg( 'v', 'abiertos', $base ) ); ?>">&larr; Pagos</a>
 <?php
	$chips = array(
		'bajas'        => array( 'Por quitar', $quitar ),
		'bajas_prog'   => array( 'Programadas', $prog ),
		'bajas_hoy'    => array( 'Hoy', $hoy_n ),
		'bajas_hechas' => array( 'Quitadas', 0 ),
	);
	foreach ( $chips as $k => $c ) {
		printf( '<a class="%s" href="%s">%s%s</a>',
			$v === $k ? 'on' : '',
			esc_url( add_query_arg( 'v', $k, $base ) ),
			esc_html( $c[0] ),
			$c[1] ? ' <b>' . (int) $c[1] . '</b>' : '' );
	}
 ?>
 </div>

 <?php
	$tit = array(
		'bajas'        => array( 'Por quitar de TrainingPeaks', 'su acceso ya ha vencido' ),
		'bajas_prog'   => array( 'Programadas', 'siguen con acceso pagado' ),
		'bajas_hoy'    => array( 'Bajas de hoy', 'pedidas hoy' ),
		'bajas_hechas' => array( 'Ya quitadas', 'nada que hacer' ),
	);
	$t = isset( $tit[ $v ] ) ? $tit[ $v ] : $tit['bajas'];
 ?>
 <h2><?php echo esc_html( $t[0] ); ?> <span><?php echo count( $lista ) . ' · ' . esc_html( $t[1] ); ?></span></h2>

 <?php if ( ! $lista ) : ?>
  <div class="zero"><span class="em">&#127958;</span><b>Nada por aquí</b><p>No hay bajas en esta vista.</p></div>
 <?php endif; ?>

 <?php foreach ( $lista as $b ) :
	if ( $b['hecho'] )            $e = array( 'Quitado', 'gr' );
	elseif ( ! $b['fin'] )        $e = array( 'Sin fecha de fin', 'gy' );
	elseif ( $b['dias'] === 0 )   $e = array( 'Quitar hoy', '' );
	elseif ( $b['vence_ya'] )     $e = array( 'Venció hace ' . abs( $b['dias'] ) . ' d', '' );
	else                          $e = array( 'Quitar en ' . $b['dias'] . ' d', 'am' );
 ?>
 <article class="caso<?php echo $b['hecho'] ? ' q' : ''; ?>">
  <div class="f1">
   <span class="tag <?php echo esc_attr( $e[1] ); ?>"><?php echo esc_html( $e[0] ); ?></span>
   <span class="hace">baja el <?php echo esc_html( $b['baja'] ? wp_date( 'j M', strtotime( $b['baja'] ) ) : '?' ); ?></span>
  </div>
  <div class="nom"><?php echo esc_html( $b['cliente'] ?: '(sin nombre)' ); ?></div>
  <div class="sub"><?php echo esc_html( $b['email'] ); ?></div>
  <div class="meta">
   <?php if ( $b['fin'] ) : ?>
    <span class="pi<?php echo $b['vence_ya'] && ! $b['hecho'] ? ' al' : ''; ?>">quitar el <b><?php echo esc_html( wp_date( 'j M', strtotime( $b['fin'] ) ) ); ?></b></span>
   <?php endif; ?>
   <span class="pi"><?php echo $b['activa'] ? 'acceso vivo' : 'ya sin acceso'; ?></span>
  </div>

  <?php $plan = class_exists( 'AUmbral_Planes' ) ? AUmbral_Planes::i()->plan_de( $b['email'] ) : ''; ?>
  <?php if ( $plan ) : ?><div class="acc" style="margin-top:12px"><?php echo esc_html( $plan ); ?></div><?php endif; ?>

  <?php if ( ! $b['hecho'] && ! $b['vence_ya'] ) : ?>
   <div class="acc">Pagó hasta el <?php echo esc_html( wp_date( 'l j \d\e F', strtotime( $b['fin'] ) ) ); ?>. Hasta ese día mantiene el plan.</div>
  <?php endif; ?>

  <?php if ( $b['hecho'] ) : ?>
   <div class="hecho">&#10003;<span>Quitado de TrainingPeaks el <?php echo esc_html( wp_date( 'd/m/Y', $b['hecho_el'] ) ); ?></span></div>
  <?php endif; ?>

  <div class="bts">
   <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:contents">
    <?php wp_nonce_field( 'aup_baja_hecha' ); ?>
    <input type="hidden" name="action" value="aup_baja_hecha">
    <input type="hidden" name="sub_id" value="<?php echo (int) $b['id']; ?>">
    <?php if ( $b['hecho'] ) : ?>
     <input type="hidden" name="deshacer" value="1"><button class="b o">Reabrir</button>
    <?php else : ?>
     <button class="b p">Quitado de TP</button>
    <?php endif; ?>
   </form>
   <a class="b o" href="<?php echo esc_url( $b['url_admin'] ); ?>" target="_blank" rel="noopener">Suscripción</a>
  </div>
 </article>
 <?php endforeach;
	}

	/* ───────────── Cuerpo de la pantalla ───────────── */

	public function cuerpo( $ctx ) {
		if ( sanitize_key( $ctx['query']['v'] ?? '' ) === 'bclb' ) {
			$this->cuerpo_bclb( $ctx );
			return;
		}
		if ( strpos( sanitize_key( $ctx['query']['v'] ?? '' ), 'baja' ) === 0 ) {
			$this->cuerpo_bajas( $ctx );
			return;
		}
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
			if ( $filtro === 'insistir' )   return false; // se sirve aparte, ver mas abajo
			if ( $filtro === 'sustituida' ) return in_array( $x['veredicto'], array( 'SUSTITUIDA', 'DUPLICADA' ), true );
			if ( $filtro === 'archivo' )    return in_array( $x['veredicto'], array( 'PERDIDA', 'RESUELTO', 'SUSTITUIDA', 'DUPLICADA' ), true );
			if ( $filtro === 'todos' )      return true;
			return strtolower( $x['veredicto'] ) === $filtro;
		} );

		$reint = $this->insistir();
		if ( $filtro === 'insistir' ) $lista = $reint;

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
		'insistir'    => array( 'Insistir', count( $reint ) ),
		'bclb'        => array( 'BCLB', 0 ),
		'bajas'       => array( 'Bajas', $this->por_quitar() ),
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
	'insistir'    => array( 'Sin respuesta', 'escritos y siguen sin pagar' ),
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
  <?php $plan = class_exists( 'AUmbral_Planes' ) ? AUmbral_Planes::i()->plan_de( $x['email'] ) : ''; ?>
  <?php if ( $plan ) : ?><div class="acc" style="margin-top:8px"><?php echo esc_html( $plan ); ?></div><?php endif; ?>

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

  <?php
	$dd = ! empty( $x['gestion']['fecha'] ) ? (int) floor( ( current_time( 'timestamp' ) - (int) $x['gestion']['fecha'] ) / DAY_IN_SECONDS ) : 0;
	$insiste = $x['gestionado'] && $dd >= self::DIAS_INSISTIR
		&& in_array( $x['veredicto'], array( 'CONTACTAR', 'REVISAR', 'ALTA', 'ESPERAR' ), true )
		&& ( AUP_Pagos::i()->clientes()[ strtolower( (string) $x['email'] ) ]['estado'] ?? '' ) !== 'active';
  ?>
  <?php if ( $insiste ) : ?>
   <div class="acc" style="background:var(--rs);color:var(--r)">
    <b>Le escribiste hace <?php echo (int) $dd; ?> días y sigue sin pagar.</b>
    <?php echo $dd >= 21 ? ' Ya son tres semanas: quizá toque darlo por perdido.' : ' Toca insistir.'; ?>
   </div>
  <?php endif; ?>

  <?php if ( $x['gestionado'] ) : ?>
   <div class="hecho">&#10003;<span>Gestionado el <?php echo esc_html( wp_date( 'd/m/Y', $x['gestion']['fecha'] ) ); ?><?php echo $x['gestion']['nota'] ? ' — ' . esc_html( $x['gestion']['nota'] ) : ''; ?></span></div>
  <?php endif; ?>

  <form class="nf" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
   <?php wp_nonce_field( 'aup_pagos_gestionar' ); ?>
   <input type="hidden" name="action" value="aup_pagos_gestionar">
   <input type="hidden" name="sub_id" value="<?php echo (int) $x['sub_id']; ?>">
   <input type="hidden" name="order_id" value="<?php echo (int) $x['order_id']; ?>">
   <?php if ( $x['gestionado'] ) : ?>
    <button class="b<?php echo $insiste ? ' p' : ''; ?>" name="nota" value="Insistido">Insistido hoy</button>
    <button class="b o" name="deshacer" value="1">Reabrir</button>
   <?php else : ?>
    <input type="text" name="nota" placeholder="nota rápida…"><button class="b">Hecho</button>
   <?php endif; ?>
  </form>
 </article>
 <?php endforeach;
	}
}
