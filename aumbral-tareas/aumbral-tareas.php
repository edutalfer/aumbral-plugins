<?php
/**
 * Plugin Name: A Umbral · Tareas
 * Description: Módulo «tareas» de la app interna. Trabaja sobre la misma tabla que el tablero de /tareas/, así que ambos se ven siempre lo mismo.
 * Version: 1.0.0
 * Author: A Umbral
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class AUmbral_Tareas {

	const SLUG = 'tareas';

	private static $inst;
	public static function i() { return self::$inst ?: ( self::$inst = new self() ); }

	public static function tabla() { global $wpdb; return $wpdb->prefix . 'aumbral_tasks'; }

	/** Mismos usuarios que el tablero clásico. */
	public static function permitidos() { return defined( 'AUMBRAL_TASKS_ALLOWED_USERS' ) ? AUMBRAL_TASKS_ALLOWED_USERS : array( 1, 368 ); }
	public static function puede() { return in_array( (int) get_current_user_id(), self::permitidos(), true ); }

	/** Quién es el usuario actual dentro del reparto de tareas. */
	public static function yo() {
		$id = (int) get_current_user_id();
		if ( $id === 368 ) return 'julia';
		if ( $id === 1 )   return 'eduardo';
		return '';
	}

	private static $estados   = array( 'pendiente', 'progreso', 'hecho', 'horizonte' );
	private static $personas  = array( 'eduardo', 'julia', 'ambos' );
	private static $prioridad = array( 'baja', 'media', 'alta' );

	private function __construct() {
		add_filter( 'aumbral_app_modulos', array( $this, 'registrar' ) );
		add_action( 'admin_post_aumbral_tareas_accion', array( $this, 'accion' ) );
		add_filter( 'aumbral_app_resumen', array( $this, 'resumen' ), 10, 2 );
	}

	public function registrar( $m ) {
		if ( ! self::puede() ) return $m;
		$m[ self::SLUG ] = array(
			'nav'    => 'Tareas',
			'titulo' => 'Tareas',
			'orden'  => 30,
			'cap'    => 'read',
			'badge'  => array( $this, 'badge' ),
			'render' => array( $this, 'cuerpo' ),
		);
		return $m;
	}

	/** Bloque del resumen diario: tareas de esa persona con fecha para hoy o pasada. */
	public function resumen( $bloques, $uid = 0 ) {
		$yo = $uid === 368 ? 'julia' : ( $uid === 1 ? 'eduardo' : '' );
		if ( ! $yo ) return $bloques;

		$hoy    = current_time( 'Y-m-d' );
		$manana = gmdate( 'Y-m-d', strtotime( $hoy . ' +1 day' ) );
		$items  = array();

		foreach ( $this->filas(
			"status IN ('pendiente','progreso') AND assigned_to IN (%s,'ambos') AND due_date IS NOT NULL AND due_date <= %s",
			array( $yo, $manana )
		) as $f ) {
			$items[] = array(
				'texto'   => $f['text'],
				'detalle' => $f['due_date'] === $hoy ? 'Para hoy.'
					: ( $f['due_date'] === $manana ? 'Para mañana.' : 'Se pasó el ' . wp_date( 'j M', strtotime( $f['due_date'] ) ) . '.' ),
				'urgente' => $f['due_date'] <= $hoy,
			);
		}

		if ( $items ) {
			$bloques[] = array(
				'titulo' => 'Tareas',
				'url'    => aumbral_app_url( self::SLUG ),
				'items'  => $items,
			);
		}
		return $bloques;
	}

	/* ───────────── Datos ───────────── */

	public function filas( $where = '1=1', $args = array() ) {
		global $wpdb;
		$t = self::tabla();
		$sql = "SELECT * FROM $t WHERE $where
			ORDER BY FIELD(status,'progreso','pendiente','horizonte','hecho'),
			         (due_date IS NULL), due_date ASC,
			         FIELD(priority,'alta','media','baja'), created_at ASC";
		if ( $args ) $sql = $wpdb->prepare( $sql, $args );
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	public function contadores() {
		global $wpdb;
		$t   = self::tabla();
		$hoy = current_time( 'Y-m-d' );
		$yo  = self::yo();
		return array(
			'abiertas'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status IN ('pendiente','progreso')" ),
			'progreso'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status='progreso'" ),
			'horizonte' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status='horizonte'" ),
			'vencidas'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status IN ('pendiente','progreso') AND due_date IS NOT NULL AND due_date <= %s", $hoy ) ),
			'mias'      => $yo ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status IN ('pendiente','progreso') AND assigned_to IN (%s,'ambos')", $yo ) ) : 0,
		);
	}

	public function badge() {
		$c = $this->contadores();
		return $c['mias'] ?: $c['abiertas'];
	}

	/* ───────────── Acciones ───────────── */

	public function accion() {
		if ( ! self::puede() ) wp_die( 'Sin permiso' );
		check_admin_referer( 'aumbral_tareas' );

		global $wpdb;
		$t   = self::tabla();
		$que = sanitize_key( $_POST['que'] ?? '' );
		$id  = absint( $_POST['id'] ?? 0 );

		if ( $que === 'crear' ) {
			$texto = sanitize_text_field( wp_unslash( $_POST['text'] ?? '' ) );
			if ( $texto !== '' ) {
				$wpdb->insert( $t, array(
					'text'        => $texto,
					'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
					'status'      => $this->uno( $_POST['status'] ?? '', self::$estados, 'pendiente' ),
					'assigned_to' => $this->uno( $_POST['assigned_to'] ?? '', self::$personas, self::yo() ?: 'ambos' ),
					'due_date'    => $this->fecha( $_POST['due_date'] ?? '' ),
					'priority'    => $this->uno( $_POST['priority'] ?? '', self::$prioridad, 'media' ),
					'created_by'  => get_current_user_id(),
					'created_at'  => current_time( 'mysql' ),
					'updated_at'  => current_time( 'mysql' ),
				) );
			}
		}

		if ( $id ) {
			if ( $que === 'estado' ) {
				$wpdb->update( $t, array(
					'status'     => $this->uno( $_POST['status'] ?? '', self::$estados, 'pendiente' ),
					'updated_at' => current_time( 'mysql' ),
				), array( 'id' => $id ) );
			}
			if ( $que === 'editar' ) {
				$up = array(
					'status'      => $this->uno( $_POST['status'] ?? '', self::$estados, 'pendiente' ),
					'assigned_to' => $this->uno( $_POST['assigned_to'] ?? '', self::$personas, 'ambos' ),
					'priority'    => $this->uno( $_POST['priority'] ?? '', self::$prioridad, 'media' ),
					'due_date'    => $this->fecha( $_POST['due_date'] ?? '' ),
					'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
					'updated_at'  => current_time( 'mysql' ),
				);
				$texto = sanitize_text_field( wp_unslash( $_POST['text'] ?? '' ) );
				if ( $texto !== '' ) $up['text'] = $texto;
				$wpdb->update( $t, $up, array( 'id' => $id ) );
			}
			if ( $que === 'borrar' ) {
				$wpdb->delete( $t, array( 'id' => $id ), array( '%d' ) );
			}
		}

		wp_safe_redirect( wp_get_referer() ?: aumbral_app_url( self::SLUG ) );
		exit;
	}

	private function uno( $v, $lista, $def ) {
		$v = sanitize_key( $v );
		return in_array( $v, $lista, true ) ? $v : $def;
	}

	private function fecha( $v ) {
		$v = sanitize_text_field( $v );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
	}

	/* ───────────── Pantalla ───────────── */

	public function cuerpo( $ctx ) {
		$base = $ctx['base'];
		$c    = $this->contadores();
		$hoy  = current_time( 'Y-m-d' );
		$yo   = self::yo();
		$v    = sanitize_key( $ctx['query']['v'] ?? ( $yo ? 'mias' : 'abiertas' ) );

		if ( $v === 'mias' && $yo )      $filas = $this->filas( "status IN ('pendiente','progreso') AND assigned_to IN (%s,'ambos')", array( $yo ) );
		elseif ( $v === 'eduardo' )      $filas = $this->filas( "status IN ('pendiente','progreso') AND assigned_to IN ('eduardo','ambos')" );
		elseif ( $v === 'julia' )        $filas = $this->filas( "status IN ('pendiente','progreso') AND assigned_to IN ('julia','ambos')" );
		elseif ( $v === 'horizonte' )    $filas = $this->filas( "status='horizonte'" );
		elseif ( $v === 'hechas' )       $filas = $this->filas( "status='hecho'" );
		else                             $filas = $this->filas( "status IN ('pendiente','progreso')" );

		$nom = array( 'eduardo' => 'Eduardo', 'julia' => 'Julia', 'ambos' => 'Los dos' );
		?>
 <div class="card">
 <?php if ( $c['abiertas'] ) : ?>
  <div class="lbl">Tareas abiertas</div>
  <div class="big"><?php echo (int) $c['abiertas']; ?></div>
  <div class="leg" style="margin-top:14px">
   <?php if ( $c['vencidas'] ) : ?>
   <div><span class="dot" style="background:var(--r)"></span><b><?php echo (int) $c['vencidas']; ?></b> con fecha cumplida</div>
   <?php endif; ?>
   <div><span class="dot" style="background:var(--az)"></span><b><?php echo (int) $c['progreso']; ?></b> en curso</div>
   <?php if ( $yo ) : ?>
   <div><span class="dot" style="background:var(--am)"></span><b><?php echo (int) $c['mias']; ?></b> tuyas</div>
   <?php endif; ?>
  </div>
 <?php else : ?>
  <div class="okh"><span class="em">&#9989;</span><div>
   <div style="font-size:17px;font-weight:600">Sin tareas abiertas</div>
   <div class="lbl" style="margin-top:3px"><?php echo (int) $c['horizonte']; ?> en el horizonte</div>
  </div></div>
 <?php endif; ?>

  <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:18px">
   <?php wp_nonce_field( 'aumbral_tareas' ); ?>
   <input type="hidden" name="action" value="aumbral_tareas_accion">
   <input type="hidden" name="que" value="crear">
   <div class="nf"><input type="text" name="text" placeholder="Nueva tarea…" required></div>
   <div class="nf"><textarea name="description" rows="2" placeholder="Descripción (opcional)…"></textarea></div>
   <div class="nf">
    <select name="status">
     <option value="pendiente">Pendiente</option>
     <option value="progreso">En curso</option>
     <option value="horizonte">Horizonte</option>
    </select>
    <select name="assigned_to">
     <option value="eduardo"<?php selected( $yo, 'eduardo' ); ?>>Eduardo</option>
     <option value="julia"<?php selected( $yo, 'julia' ); ?>>Julia</option>
     <option value="ambos">Los dos</option>
    </select>
    <select name="priority">
     <option value="media">Normal</option>
     <option value="alta">Alta</option>
     <option value="baja">Baja</option>
    </select>
   </div>
   <div class="nf"><input type="date" name="due_date"><button class="b p">Añadir</button></div>
  </form>
 </div>

 <div class="chips">
 <?php
	$chips = array();
	if ( $yo ) $chips['mias'] = array( 'Mías', $c['mias'] );
	$chips['abiertas']  = array( 'Todas', $c['abiertas'] );
	$chips['eduardo']   = array( 'Eduardo', 0 );
	$chips['julia']     = array( 'Julia', 0 );
	$chips['horizonte'] = array( 'Horizonte', $c['horizonte'] );
	$chips['hechas']    = array( 'Hechas', 0 );
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
		'mias'      => array( 'Para ti', 'tuyas y compartidas' ),
		'abiertas'  => array( 'Abiertas', 'pendientes y en curso' ),
		'eduardo'   => array( 'Eduardo', 'abiertas' ),
		'julia'     => array( 'Julia', 'abiertas' ),
		'horizonte' => array( 'Horizonte', 'sin fecha, algún día' ),
		'hechas'    => array( 'Hechas', 'cerradas' ),
	);
	$t = isset( $tit[ $v ] ) ? $tit[ $v ] : $tit['abiertas'];
 ?>
 <h2><?php echo esc_html( $t[0] ); ?> <span><?php echo count( $filas ) . ' · ' . esc_html( $t[1] ); ?></span></h2>

 <?php if ( ! $filas ) : ?>
  <div class="zero"><span class="em">&#127807;</span><b>Nada por aquí</b><p>No hay tareas en esta vista.</p></div>
 <?php endif; ?>

 <?php foreach ( $filas as $f ) :
	$vencida = $f['due_date'] && $f['due_date'] <= $hoy && in_array( $f['status'], array( 'pendiente', 'progreso' ), true );
	$et = array(
		'pendiente'  => array( 'Pendiente', 'gy' ),
		'progreso'   => array( 'En curso', 'az' ),
		'hecho'      => array( 'Hecha', 'gr' ),
		'horizonte'  => array( 'Horizonte', 'gy' ),
	);
	$e = isset( $et[ $f['status'] ] ) ? $et[ $f['status'] ] : array( $f['status'], 'gy' );
	if ( $vencida ) $e = array( $f['due_date'] === $hoy ? 'Para hoy' : 'Se pasó', '' );
 ?>
 <article class="caso<?php echo $f['status'] === 'hecho' ? ' q' : ''; ?>">
  <div class="f1">
   <span class="tag <?php echo esc_attr( $e[1] ); ?>"><?php echo esc_html( $e[0] ); ?></span>
   <span class="hace"><?php echo esc_html( $nom[ $f['assigned_to'] ] ?? $f['assigned_to'] ); ?></span>
  </div>
  <div class="nom" style="font-size:16px"><?php echo esc_html( $f['text'] ); ?></div>
  <?php if ( trim( (string) $f['description'] ) !== '' ) : ?>
   <div class="sub" style="white-space:pre-wrap"><?php echo esc_html( $f['description'] ); ?></div>
  <?php endif; ?>

  <div class="meta">
   <?php if ( $f['due_date'] ) : ?>
    <span class="pi<?php echo $vencida ? ' al' : ''; ?>"><?php echo esc_html( wp_date( 'j M', strtotime( $f['due_date'] ) ) ); ?></span>
   <?php endif; ?>
   <?php if ( $f['priority'] === 'alta' ) : ?><span class="pi al">Alta</span><?php endif; ?>
   <?php if ( $f['priority'] === 'baja' ) : ?><span class="pi">Baja</span><?php endif; ?>
  </div>

  <div class="bts">
   <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:contents">
    <?php wp_nonce_field( 'aumbral_tareas' ); ?>
    <input type="hidden" name="action" value="aumbral_tareas_accion">
    <input type="hidden" name="que" value="estado">
    <input type="hidden" name="id" value="<?php echo (int) $f['id']; ?>">
    <?php if ( $f['status'] === 'hecho' ) : ?>
     <button class="b o" name="status" value="pendiente">Reabrir</button>
    <?php else : ?>
     <button class="b p" name="status" value="hecho">Hecha</button>
     <?php if ( $f['status'] !== 'progreso' ) : ?>
      <button class="b" name="status" value="progreso">Empezar</button>
     <?php else : ?>
      <button class="b" name="status" value="pendiente">Pausar</button>
     <?php endif; ?>
     <?php if ( $f['status'] !== 'horizonte' ) : ?>
      <button class="b o" name="status" value="horizonte">Al horizonte</button>
     <?php endif; ?>
    <?php endif; ?>
   </form>
  </div>

  <details>
   <summary>Ajustar</summary>
   <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'aumbral_tareas' ); ?>
    <input type="hidden" name="action" value="aumbral_tareas_accion">
    <input type="hidden" name="que" value="editar">
    <input type="hidden" name="id" value="<?php echo (int) $f['id']; ?>">
    <div class="nf"><input type="text" name="text" value="<?php echo esc_attr( $f['text'] ); ?>"></div>
    <div class="nf"><textarea name="description" rows="3" placeholder="detalle…"><?php echo esc_textarea( $f['description'] ); ?></textarea></div>
    <div class="nf">
     <select name="status">
      <option value="pendiente"<?php selected( $f['status'], 'pendiente' ); ?>>Pendiente</option>
      <option value="progreso"<?php selected( $f['status'], 'progreso' ); ?>>En curso</option>
      <option value="horizonte"<?php selected( $f['status'], 'horizonte' ); ?>>Horizonte</option>
      <option value="hecho"<?php selected( $f['status'], 'hecho' ); ?>>Hecha</option>
     </select>
     <select name="assigned_to">
      <option value="eduardo"<?php selected( $f['assigned_to'], 'eduardo' ); ?>>Eduardo</option>
      <option value="julia"<?php selected( $f['assigned_to'], 'julia' ); ?>>Julia</option>
      <option value="ambos"<?php selected( $f['assigned_to'], 'ambos' ); ?>>Los dos</option>
     </select>
     <select name="priority">
      <option value="media"<?php selected( $f['priority'], 'media' ); ?>>Normal</option>
      <option value="alta"<?php selected( $f['priority'], 'alta' ); ?>>Alta</option>
      <option value="baja"<?php selected( $f['priority'], 'baja' ); ?>>Baja</option>
     </select>
    </div>
    <div class="nf"><input type="date" name="due_date" value="<?php echo esc_attr( $f['due_date'] ); ?>"><button class="b">Guardar</button></div>
   </form>
   <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('¿Borrar esta tarea?')">
    <?php wp_nonce_field( 'aumbral_tareas' ); ?>
    <input type="hidden" name="action" value="aumbral_tareas_accion">
    <input type="hidden" name="que" value="borrar">
    <input type="hidden" name="id" value="<?php echo (int) $f['id']; ?>">
    <div class="nf"><button class="b o">Borrar</button></div>
   </form>
  </details>
 </article>
 <?php endforeach;
	}
}

add_action( 'plugins_loaded', function () { AUmbral_Tareas::i(); }, 20 );
