<?php
/**
 * A Umbral · Comprobación de salud de la app interna.
 * Se ejecuta con:  wp eval-file wp-content/plugins/aumbral-app/checks.php --allow-root
 * Devuelve una línea por comprobación y un resumen. Salida distinta de cero si algo falla.
 *
 * Pensado para lanzarse DESPUÉS de cada cambio en cualquiera de los plugins.
 */

if ( ! defined( 'WP_CLI' ) ) { echo "Solo por WP-CLI\n"; return; }

$fallos = 0;
$avisos = 0;
$LENTO  = 2000; // ms

function ok( $t, $extra = '' )    { printf( "  \e[32mOK\e[0m    %-46s %s\n", $t, $extra ); }
function mal( $t, $extra = '' )   { global $fallos; $fallos++; printf( "  \e[31mFALLO\e[0m %-46s %s\n", $t, $extra ); }
function avi( $t, $extra = '' )   { global $avisos; $avisos++; printf( "  \e[33mAVISO\e[0m %-46s %s\n", $t, $extra ); }

// Cualquier warning o notice cuenta como fallo: es lo que delata los errores silenciosos.
$capturados = array();
set_error_handler( function ( $no, $str, $file, $line ) use ( &$capturados ) {
	if ( strpos( $file, 'aumbral-' ) === false ) return true; // solo nuestro código
	$capturados[] = basename( $file ) . ":$line $str";
	return true;
}, E_ALL );

wp_set_current_user( 1 );

echo "\n\e[1mPLUGINS\e[0m\n";
foreach ( array( 'aumbral-app', 'aumbral-pagos', 'aumbral-planes', 'aumbral-tareas' ) as $p ) {
	is_plugin_active( $p . '/' . $p . '.php' ) ? ok( "$p activo" ) : mal( "$p NO activo" );
}

echo "\n\e[1mMÓDULOS Y PANTALLAS\e[0m\n";
$mods = AUmbral_App::i()->modulos();
$esperados = array( 'pagos', 'planes', 'tareas' );
foreach ( $esperados as $e ) {
	isset( $mods[ $e ] ) ? ok( "módulo $e registrado" ) : mal( "módulo $e NO registrado" );
}

// Vistas de cada módulo. Si una entra en bucle, el proceso muere y el wrapper lo detecta.
$vistas = array(
	'pagos'  => array( 'abiertos', 'contactar', 'esperar', 'alta', 'archivo', 'gestionados', 'todos', 'bajas', 'bajas_prog', 'bajas_hoy', 'bajas_hechas' ),
	'planes' => array( 'pendientes', 'vencen', 'inicio', 'hechos', 'todos' ),
	'tareas' => array( 'mias', 'abiertas', 'eduardo', 'julia', 'horizonte', 'hechas' ),
);
foreach ( $vistas as $m => $vs ) {
	if ( ! isset( $mods[ $m ] ) ) continue;

	$a = microtime( true );
	$n = call_user_func( $mods[ $m ]['badge'] );
	$ms = round( ( microtime( true ) - $a ) * 1000 );
	$ms > $LENTO ? avi( "badge de $m lento", "{$ms} ms (badge=$n)" ) : ok( "badge de $m", "{$ms} ms · $n" );

	foreach ( $vs as $v ) {
		$a = microtime( true );
		ob_start();
		call_user_func( $mods[ $m ]['render'], array( 'slug' => $m, 'base' => AUmbral_App::url( $m ), 'query' => array( 'v' => $v ) ) );
		$html = ob_get_clean();
		$ms   = round( ( microtime( true ) - $a ) * 1000 );

		if ( strlen( $html ) < 200 )          mal( "$m/$v pinta vacío", strlen( $html ) . ' bytes' );
		elseif ( stripos( $html, 'Fatal error' ) !== false ) mal( "$m/$v con error fatal" );
		elseif ( $ms > $LENTO )               avi( "$m/$v lenta", "{$ms} ms" );
		else                                  ok( "$m/$v", sprintf( '%4d ms · %2d fichas', $ms, substr_count( $html, '<article' ) ) );
	}
}

echo "\n\e[1mDATOS\e[0m\n";
global $wpdb;
$t = AUmbral_Planes::tabla();
$wpdb->get_var( "SHOW TABLES LIKE '$t'" ) === $t ? ok( 'tabla de planes existe' ) : mal( 'falta la tabla de planes' );

$sin = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE estado='inicio' AND fecha_paso IS NULL" );
$sin ? mal( 'en inicio sin fecha de paso', "$sin fichas" ) : ok( 'todas las de inicio tienen fecha' );

$huerf = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE email='' OR email IS NULL" );
$huerf ? avi( 'solicitudes sin email', "$huerf" ) : ok( 'todas las solicitudes con email' );

$pend = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE estado='pendiente'" );
ok( 'solicitudes pendientes', $pend );

$atras = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE estado='inicio' AND fecha_paso < %s", current_time( 'Y-m-d' ) ) );
$atras ? avi( 'vencimientos sin cerrar', "$atras personas" ) : ok( 'sin vencimientos atrasados' );

// Sincronización: nada en Elementor que no esté importado
$falta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}e_submissions s
	LEFT JOIN $t p ON p.submission_id = s.id
	WHERE p.submission_id IS NULL AND s.created_at >= '" . gmdate( 'Y-m-d', strtotime( '-30 days' ) ) . "'" );
$falta ? avi( 'envíos sin importar', "$falta (se importan al abrir Planes)" ) : ok( 'planes sincronizado con Elementor' );

$tar = AUmbral_Tareas::tabla();
$wpdb->get_var( "SHOW TABLES LIKE '$tar'" ) === $tar ? ok( 'tabla de tareas existe' ) : mal( 'falta la tabla de tareas' );

echo "\n\e[1mFORMULARIOS\e[0m\n";
$fw = $wpdb->get_results( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_name LIKE 'fw-%'", ARRAY_A );
foreach ( $fw as $p ) {
	$d = get_post_meta( $p['ID'], '_elementor_data', true );
	$d = is_string( $d ) ? $d : wp_json_encode( $d );
	strpos( $d, 'Vienes de hacer otro plan' ) !== false
		? ok( "{$p['post_name']} pregunta por el plan previo" )
		: avi( "{$p['post_name']} SIN la pregunta del plan previo", 'la app tendrá que inferirlo' );
}

echo "\n\e[1mCORREO\e[0m\n";
get_option( 'aumbral_planes_silencio' )
	? ok( 'aviso de formulario silenciado', (int) get_option( 'aumbral_planes_silenciados' ) . ' retenidos' )
	: ok( 'aviso de formulario llegando a Gmail' );

restore_error_handler();
if ( $capturados ) {
	echo "\n\e[1mAVISOS DE PHP EN NUESTRO CÓDIGO\e[0m\n";
	foreach ( array_slice( array_unique( $capturados ), 0, 12 ) as $c ) mal( 'php', $c );
}

echo "\n";
if ( $fallos ) {
	echo "\e[31m✗ $fallos fallo(s)" . ( $avisos ? " y $avisos aviso(s)" : '' ) . "\e[0m\n\n";
	WP_CLI::halt( 1 );
}
echo "\e[32m✓ Todo correcto" . ( $avisos ? " · $avisos aviso(s)" : '' ) . "\e[0m\n\n";
