<?php
/**
 * Plugin Name: A Umbral · App interna
 * Description: Contenedor de la aplicación interna en /app/. Aporta ruta, PWA, cabecera, navegación e identidad comunes. Cada área (pagos, planes, tareas) se registra como módulo mediante el filtro «aumbral_app_modulos».
 * Version: 1.0.0
 * Author: A Umbral
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class AUmbral_App {

	const RUTA = 'app';
	const CAP  = 'manage_woocommerce';

	private static $inst;
	private $mods = null;

	public static function i() { return self::$inst ?: ( self::$inst = new self() ); }

	private function __construct() {
		add_action( 'template_redirect', array( $this, 'router' ), 0 );
	}

	/* ───────────── Módulos ───────────── */

	/**
	 * Cada módulo es: slug => array(
	 *   'nav'    => 'Pagos',                 etiqueta de la barra inferior
	 *   'titulo' => 'Pagos',                 título de la pestaña del navegador
	 *   'orden'  => 10,
	 *   'cap'    => 'manage_woocommerce',    capacidad requerida
	 *   'badge'  => callable|int|null,       número rojo sobre la pestaña
	 *   'render' => callable( array $ctx ),  pinta el cuerpo dentro de .w.hero
	 * )
	 */
	public function modulos() {
		if ( is_array( $this->mods ) ) return $this->mods;

		$m = apply_filters( 'aumbral_app_modulos', array() );
		$m = is_array( $m ) ? $m : array();

		foreach ( $m as $slug => $d ) {
			$cap = isset( $d['cap'] ) ? $d['cap'] : self::CAP;
			if ( ! current_user_can( $cap ) || empty( $d['render'] ) || ! is_callable( $d['render'] ) ) {
				unset( $m[ $slug ] );
			}
		}
		uasort( $m, function ( $a, $b ) {
			return ( isset( $a['orden'] ) ? $a['orden'] : 50 ) <=> ( isset( $b['orden'] ) ? $b['orden'] : 50 );
		} );

		return $this->mods = $m;
	}

	public static function url( $slug = '', $args = array() ) {
		$u = home_url( '/' . self::RUTA . '/' . ( $slug ? $slug . '/' : '' ) );
		return $args ? add_query_arg( $args, $u ) : $u;
	}

	private function badge( $d ) {
		if ( empty( $d['badge'] ) ) return 0;
		$b = $d['badge'];
		return (int) ( is_callable( $b ) ? call_user_func( $b ) : $b );
	}

	/* ───────────── Enrutado ───────────── */

	private function segmento() {
		$p = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		$p = trim( (string) $p, '/' );
		if ( $p === self::RUTA ) return array( 'app', '' );
		if ( strpos( $p, self::RUTA . '/' ) !== 0 ) return array( '', '' );

		$resto = substr( $p, strlen( self::RUTA ) + 1 );
		if ( $resto === 'sw.js' )                 return array( 'sw', '' );
		if ( $resto === 'manifest.webmanifest' )  return array( 'manifest', '' );
		if ( $resto === 'icon' )                  return array( 'icon', '' );
		if ( $resto === 'icon-mask' )             return array( 'iconmask', '' );
		if ( strpos( $resto, '/' ) !== false )    return array( '', '' );

		return array( 'app', sanitize_key( $resto ) );
	}

	public function router() {
		list( $s, $slug ) = $this->segmento();
		if ( ! $s ) return;

		do_action( 'litespeed_control_set_nocache', 'aplicacion interna A Umbral' );
		nocache_headers();
		status_header( 200 );
		global $wp_query; if ( $wp_query ) { $wp_query->is_404 = false; }

		if ( $s === 'sw' )       { $this->sw(); }
		if ( $s === 'manifest' ) { $this->manifest(); }
		if ( $s === 'icon' )     { $this->icon( false ); }
		if ( $s === 'iconmask' ) { $this->icon( true ); }

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url( $slug ) ) ); exit;
		}

		$mods = $this->modulos();
		if ( ! $mods ) {
			status_header( 403 );
			wp_die( 'No tienes permiso para ver esta página.', 'Sin acceso', array( 'response' => 403 ) );
		}

		if ( ! $slug || ! isset( $mods[ $slug ] ) ) {
			$primero = key( $mods );
			wp_safe_redirect( self::url( $primero ) ); exit;
		}

		// Cambio de interfaz (movil / escritorio), guardado por usuario.
		if ( isset( $_GET['ui'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'aumbral_app_ui' ) ) {
			update_user_meta( get_current_user_id(), 'aumbral_app_ui', $_GET['ui'] === 'escritorio' ? 'escritorio' : 'movil' );
			$q = $_GET; unset( $q['ui'], $q['_wpnonce'] );
			wp_safe_redirect( self::url( $slug, $q ) ); exit;
		}

		status_header( 200 );
		$this->pantalla( $slug, $mods );
		exit;
	}

	/* ───────────── PWA ───────────── */

	private function manifest() {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( array(
			'name'             => 'A Umbral',
			'short_name'       => 'A Umbral',
			'start_url'        => '/' . self::RUTA . '/',
			'scope'            => '/' . self::RUTA . '/',
			'display'          => 'standalone',
			'background_color' => '#fbfaf8',
			'theme_color'      => '#fdeceb',
			'orientation'      => 'portrait',
			'icons'            => array(
				array( 'src' => '/' . self::RUTA . '/icon?s=192&v=1', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => '/' . self::RUTA . '/icon?s=512&v=1', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => '/' . self::RUTA . '/icon-mask?s=512&v=1', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
			),
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/** Service worker mínimo: no cachea datos (son privados), solo permite instalar. */
	private function sw() {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' . self::RUTA . '/' );
		echo "self.addEventListener('install',e=>self.skipWaiting());\n";
		echo "self.addEventListener('activate',e=>e.waitUntil(self.clients.claim()));\n";
		echo "self.addEventListener('fetch',e=>{});\n";
		exit;
	}

	/** Isotipo de marca con GD: bloque bajo + rampa + bloque alto, blanco sobre #ed4044. */
	private function icon( $msk = false ) {
		$s  = min( 512, max( 48, absint( $_GET['s'] ?? 192 ) ) );
		$ss = 4;
		$S  = $s * $ss;
		$im = imagecreatetruecolor( $S, $S );
		$rj = imagecolorallocate( $im, 0xed, 0x40, 0x44 );
		$bl = imagecolorallocate( $im, 0xff, 0xff, 0xff );
		imagefilledrectangle( $im, 0, 0, $S, $S, $rj );

		$fw = $msk ? 0.54 : 0.70;
		$w  = $S * $fw;
		$h  = $w * 342 / 728;
		$ox = ( $S - $w ) / 2;
		$oy = ( $S - $h ) / 2;
		$X  = function ( $f ) use ( $ox, $w ) { return (int) round( $ox + $f * $w ); };
		$Y  = function ( $f ) use ( $oy, $h ) { return (int) round( $oy + $f * $h ); };

		imagefilledrectangle( $im, $X( 0 ), $Y( 0.471 ), $X( 0.28 ), $Y( 1 ), $bl );
		imagefilledrectangle( $im, $X( 0.545 ), $Y( 0 ), $X( 1 ), $Y( 1 ), $bl );
		imagefilledpolygon( $im, array(
			$X( 0.225 ), $Y( 0.471 ),
			$X( 0.560 ), $Y( 0 ),
			$X( 0.560 ), $Y( 0.34 ),
			$X( 0.285 ), $Y( 0.94 ),
		), 4, $bl );

		$out = imagecreatetruecolor( $s, $s );
		imagecopyresampled( $out, $im, 0, 0, 0, 0, $s, $s, $S, $S );
		imagedestroy( $im );
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: public, max-age=86400' );
		imagepng( $out );
		imagedestroy( $out );
		exit;
	}

	/* ───────────── Pantalla ───────────── */

	private function pantalla( $slug, $mods ) {
		$d      = $mods[ $slug ];
		$u      = wp_get_current_user();
		$nombre = $u->first_name ?: ( explode( ' ', $u->display_name )[0] ?: 'Hola' );
		$fuent  = content_url( '/uploads/fonts/' );
		$titulo = isset( $d['titulo'] ) ? $d['titulo'] : ( isset( $d['nav'] ) ? $d['nav'] : ucfirst( $slug ) );
		$ui     = get_user_meta( get_current_user_id(), 'aumbral_app_ui', true ) === 'escritorio' ? 'escritorio' : 'movil';
		$otra   = $ui === 'escritorio' ? 'movil' : 'escritorio';
		$cambio = wp_nonce_url( self::url( $slug, array_merge( (array) $_GET, array( 'ui' => $otra ) ) ), 'aumbral_app_ui' );
		?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#fdeceb">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="A Umbral">
<link rel="manifest" href="<?php echo esc_url( self::url() . 'manifest.webmanifest' ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( self::url() . 'icon?s=192&v=1' ); ?>">
<title><?php echo esc_html( $titulo ); ?> · A Umbral</title>
<style>
@font-face{font-family:"Machina";src:url("<?php echo esc_url( $fuent ); ?>PPNeueMachina-PlainLightItalic.woff") format("woff");font-weight:300;font-style:italic;font-display:swap}
:root{
 --ink:#191614; --mut:#8c867f; --line:#efedea; --bg:#fbfaf8; --card:#fff;
 --r:#ed4044; --rs:#fdeceb; --am:#b57d16; --ams:#fdf4e3; --gr:#2f7d4f; --grs:#eaf4ee;
 --az:#2b6cb0; --azs:#eaf1f8;
 --sans:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
 --sb:env(safe-area-inset-bottom,0px);
}
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
html{-webkit-text-size-adjust:100%}
body{background:var(--bg);color:var(--ink);font:400 15px/1.5 var(--sans);padding-bottom:calc(110px + var(--sb));-webkit-font-smoothing:antialiased}
.w{max-width:620px;margin:0 auto;padding:0 20px}
.top{background:linear-gradient(175deg,var(--rs) 0%,#fdf4f2 55%,var(--bg) 100%);padding:26px 0 78px}
.hi{display:flex;align-items:center;justify-content:space-between;gap:14px}
.hi h1{font-family:"Machina",var(--sans);font-weight:300;font-style:italic;font-size:30px;letter-spacing:-.02em;line-height:1.1}
.av{width:40px;height:40px;border-radius:50%;background:var(--r);color:#fff;display:grid;place-items:center;font-size:15px;font-weight:600;flex:none;text-decoration:none}
.dia{font-size:13px;color:var(--mut);margin-top:5px}
.hero{margin-top:-58px}
.card{background:var(--card);border-radius:22px;padding:22px;box-shadow:0 2px 20px rgba(25,22,20,.06)}
.lbl{font-size:12px;color:var(--mut)}
.big{font-family:"Machina",var(--sans);font-style:italic;font-weight:300;font-size:40px;line-height:1.15;letter-spacing:-.02em;margin-top:9px}
.big sup{font-size:19px;top:-.34em;position:relative;font-style:italic;margin-left:1px}
.bar{display:flex;height:7px;border-radius:99px;overflow:hidden;background:var(--line);margin:20px 0 12px}
.bar i{display:block;height:100%}
.leg{display:flex;gap:20px;flex-wrap:wrap;font-size:12.5px}
.leg div{display:flex;align-items:center;gap:7px;color:var(--mut)}
.leg b{color:var(--ink);font-weight:600}
.dot{width:8px;height:8px;border-radius:50%;flex:none}
.okh{display:flex;align-items:center;gap:12px}
.okh .em{font-size:26px}
h2{font-size:17px;font-weight:600;letter-spacing:-.01em;margin:30px 0 12px;display:flex;justify-content:space-between;align-items:baseline;gap:12px}
h2 span{font-size:13px;font-weight:400;color:var(--mut);text-align:right}
.chips{display:flex;gap:8px;overflow-x:auto;margin:18px -20px 0;padding:0 20px 2px;scrollbar-width:none}
.chips::-webkit-scrollbar{display:none}
.chips a{flex:none;text-decoration:none;font-size:13px;font-weight:500;color:var(--mut);background:var(--card);border:1px solid var(--line);border-radius:99px;padding:8px 14px;white-space:nowrap}
.chips a.on{background:var(--ink);border-color:var(--ink);color:#fff}
.chips a b{font-weight:700}
.caso{background:var(--card);border-radius:18px;padding:18px;margin-bottom:12px;box-shadow:0 1px 10px rgba(25,22,20,.04)}
.caso.q{opacity:.72}
.tag{display:inline-flex;align-items:center;font-size:11.5px;font-weight:600;padding:5px 11px;border-radius:99px;background:var(--rs);color:var(--r)}
.tag.am{background:var(--ams);color:var(--am)}
.tag.gr{background:var(--grs);color:var(--gr)}
.tag.az{background:var(--azs);color:var(--az)}
.tag.gy{background:#f3f2f0;color:var(--mut)}
.ESPERAR .tag{background:var(--ams);color:var(--am)}
.ALTA .tag{background:#f4eddf;color:#8a6d3b}
.PERDIDA .tag,.DUPLICADA .tag{background:#f3f2f0;color:var(--mut)}
.RESUELTO .tag,.SUSTITUIDA .tag{background:var(--grs);color:var(--gr)}
.f1{display:flex;align-items:center;justify-content:space-between;gap:10px}
.hace{font-size:12px;color:var(--mut)}
.nom{font-size:18px;font-weight:600;letter-spacing:-.01em;margin-top:13px}
.sub{font-size:13px;color:var(--mut);margin-top:2px;word-break:break-all}
.meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:13px}
.pi{font-size:12px;color:var(--mut);background:var(--bg);border-radius:99px;padding:6px 11px}
.pi b{color:var(--ink);font-weight:600}
.pi.al{background:var(--rs);color:var(--r)}
.pi.ok{background:var(--grs);color:var(--gr)}
.mot{margin-top:15px;font-size:14.5px;font-weight:500}
.raw{font-size:12px;color:#a8a29a;margin-top:3px;font-weight:400}
.acc{margin-top:12px;background:var(--bg);border-radius:14px;padding:13px 15px;font-size:13.5px;line-height:1.55;color:#4a4540}
.bts{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px}
.b{border:0;border-radius:99px;padding:11px 17px;font:600 13px var(--sans);background:#f2f0ed;color:var(--ink);cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
.b.p{background:var(--r);color:#fff}
.b.o{background:transparent;border:1px solid var(--line);color:var(--mut);font-weight:500}
details{margin-top:14px}
summary{list-style:none;cursor:pointer;font-size:13px;font-weight:500;color:var(--r)}
summary::-webkit-details-marker{display:none}
.msg{margin-top:11px;background:var(--bg);border-radius:14px;padding:15px;font-size:13.5px;line-height:1.65;white-space:pre-wrap;color:#4a4540}
.lnk{margin-top:8px;font-size:11.5px;color:var(--mut);word-break:break-all;background:var(--bg);border-radius:12px;padding:11px 13px}
.hecho{margin-top:13px;font-size:12.5px;color:var(--gr);display:flex;gap:7px;align-items:flex-start}
.nf{display:flex;gap:8px;margin-top:14px}
.nf input,.nf select{flex:1;min-width:0;border:1px solid var(--line);border-radius:99px;padding:11px 16px;font:400 13px var(--sans);background:var(--bg);color:var(--ink);outline:0}
.nf input:focus,.nf select:focus{border-color:var(--r)}
.zero{background:var(--card);border-radius:18px;padding:52px 24px;text-align:center;box-shadow:0 1px 10px rgba(25,22,20,.04)}
.zero .em{font-size:32px;display:block;margin-bottom:12px}
.zero p{color:var(--mut);font-size:14px}
.zero b{display:block;font-size:17px;font-weight:600;margin-bottom:6px}
nav{position:fixed;left:0;right:0;bottom:0;padding:0 16px calc(14px + var(--sb));z-index:30;pointer-events:none}
.nav{max-width:480px;margin:0 auto;background:rgba(255,255,255,.86);backdrop-filter:blur(18px) saturate(1.6);border:1px solid rgba(25,22,20,.06);border-radius:99px;display:flex;padding:6px;box-shadow:0 6px 26px rgba(25,22,20,.11);pointer-events:auto}
.nav a{flex:1;text-align:center;text-decoration:none;color:var(--mut);font-size:12px;font-weight:500;padding:11px 4px;border-radius:99px;position:relative;white-space:nowrap}
.nav a.on{background:var(--ink);color:#fff}
.nav a i{position:absolute;top:4px;right:8px;background:var(--r);color:#fff;font-style:normal;font-size:10px;font-weight:700;min-width:16px;height:16px;border-radius:99px;display:grid;place-items:center;padding:0 4px}
.nav a.on i{background:#fff;color:var(--ink)}
.toast{position:fixed;left:50%;transform:translateX(-50%) translateY(14px);bottom:calc(96px + var(--sb));background:var(--ink);color:#fff;padding:12px 20px;border-radius:99px;font-size:13px;font-weight:500;opacity:0;transition:.22s;pointer-events:none;z-index:50}
.toast.on{opacity:1;transform:translateX(-50%) translateY(0)}
.pie{text-align:center;font-size:12px;color:#b4aea7;margin:26px 0 6px}
.pie a{color:#b4aea7}
.ui{display:none;font-size:11.5px;font-weight:600;color:var(--mut);background:rgba(255,255,255,.7);border:1px solid var(--line);border-radius:99px;padding:8px 13px;text-decoration:none;white-space:nowrap}
@media (min-width:900px){.ui{display:inline-flex}}
.nf textarea{flex:1;min-width:0;border:1px solid var(--line);border-radius:16px;padding:11px 16px;font:400 13px var(--sans);background:var(--bg);color:var(--ink);outline:0;resize:vertical}
.nf textarea:focus{border-color:var(--r)}
/* Escritorio: mas ancho y las fichas en rejilla */
body.esc .w{max-width:1180px}
body.esc .top{padding:32px 0 84px}
body.esc .hi h1{font-size:36px}
body.esc .hero{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:14px;align-items:start}
body.esc .hero>.card,body.esc .hero>.chips,body.esc .hero>h2,body.esc .hero>.pie,body.esc .hero>.zero{grid-column:1/-1}
body.esc .hero>.caso{margin-bottom:0}
body.esc .chips{margin-left:0;margin-right:0;padding-left:0;padding-right:0;flex-wrap:wrap;overflow:visible}
body.esc nav{padding-bottom:calc(20px + var(--sb))}
body.esc .nav{max-width:560px}
</style>
<?php do_action( 'aumbral_app_head', $slug ); ?>
</head>
<body class="<?php echo $ui === 'escritorio' ? 'esc' : ''; ?>">

<div class="top"><div class="w">
 <div class="hi">
  <div>
   <h1>Hola, <?php echo esc_html( $nombre ); ?></h1>
   <div class="dia"><?php echo esc_html( ucfirst( wp_date( 'l j \d\e F' ) ) ); ?></div>
  </div>
  <div style="display:flex;align-items:center;gap:10px">
   <a class="ui" href="<?php echo esc_url( $cambio ); ?>"><?php echo $ui === 'escritorio' ? 'Vista móvil' : 'Vista escritorio'; ?></a>
   <a class="av" href="<?php echo esc_url( admin_url() ); ?>" title="Ir a wp-admin"><?php echo esc_html( mb_strtoupper( mb_substr( $nombre, 0, 1 ) ) ); ?></a>
  </div>
 </div>
</div></div>

<div class="w hero">
<?php
	call_user_func( $d['render'], array(
		'slug'  => $slug,
		'base'  => self::url( $slug ),
		'query' => $_GET,
	) );
?>
 <div class="pie">Actualizado a las <?php echo esc_html( wp_date( 'H:i' ) ); ?> · <a href="<?php echo esc_url( self::url( $slug, $_GET ) ); ?>">recargar</a></div>
</div>

<nav><div class="nav">
<?php
	foreach ( $mods as $k => $md ) {
		$n = $this->badge( $md );
		printf( '<a class="%s" href="%s">%s%s</a>',
			$k === $slug ? 'on' : '',
			esc_url( self::url( $k ) ),
			esc_html( isset( $md['nav'] ) ? $md['nav'] : ucfirst( $k ) ),
			$n ? '<i>' . (int) $n . '</i>' : '' );
	}
?>
</div></nav>

<div class="toast" id="toast">Copiado</div>
<script>
function cp(id){var e=document.getElementById(id);if(!e)return;var t=e.innerText||e.textContent;
var ok=function(){aut('Copiado')};
if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(ok)}
else{var a=document.createElement('textarea');a.value=t;a.style.position='fixed';a.style.opacity=0;document.body.appendChild(a);a.select();document.execCommand('copy');document.body.removeChild(a);ok()}}
function aut(m){var b=document.getElementById('toast');b.textContent=m||'Hecho';b.classList.add('on');setTimeout(function(){b.classList.remove('on')},1400)}
if('serviceWorker' in navigator){navigator.serviceWorker.register('<?php echo esc_js( self::url() ); ?>sw.js',{scope:'<?php echo esc_js( self::url() ); ?>'}).catch(function(){})}
</script>
<?php do_action( 'aumbral_app_footer', $slug ); ?>
</body>
</html>
<?php
	}
}

add_action( 'plugins_loaded', function () {
	AUmbral_App::i();
}, 5 );

/** Atajo para módulos: AUmbral_App::url() sin acoplarse a la clase. */
function aumbral_app_url( $slug = '', $args = array() ) {
	return class_exists( 'AUmbral_App' ) ? AUmbral_App::url( $slug, $args ) : home_url( '/app/' );
}
