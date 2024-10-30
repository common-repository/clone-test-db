<?php
/*
Plugin Name: Clone Test DB
Plugin URI: https://www.560designs.com/development/clone-test-db.html
Description: Duplicate the table in another database and optimize it as a test site.
Version: 1.0.3
Author: Yuya Hoshino
Author URI: https://www.560designs.com/
Text Domain: clone-test-db
Domain Path: /languages
*/

/*  Copyright 2016 Yuya Hoshino (email : y.hoshino56@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class clone_test_db {
	public $updated = array ();
	public $error = array ();

	public function __construct() {
		load_plugin_textdomain( 'clone-test-db', false, plugin_basename( dirname ( __FILE__ ) ) . '/languages' );
		add_action( 'admin_menu', array ( $this, 'clone_test_db_admin_menu' ) );
    }

	public function clone_test_db_admin_menu() {
		$hook_suffix = add_submenu_page( 'tools.php', 'Clone Test DB', 'Clone Test DB', 'administrator', 'clone_test_db', array ( $this, 'clone_test_db_front_page' ) );
		add_action( 'admin_print_styles-' . $hook_suffix, array ( $this, 'clone_test_db_styles' ) );
	}

	public function clone_test_db_styles() {
		wp_enqueue_style( 'clone-test-db-styles', plugins_url( 'css/styles.css', __FILE__ ) );
	}

	public function clone_test_db_replace_rec( $txt_org, $search_word, $replace_word ) {
		$search_word_esc = preg_quote ( $search_word, '/' );
		$txt = '';

		if ( $txt_org && $search_word && $replace_word ) {
			$txt = $txt_org;

			// とりあえず全部置換
			$txt = str_replace ( $search_word, $replace_word, $txt );

			// シリアライズされた文字列を検索
			if ( preg_match_all ( '/s:([0-9]+):/', $txt_org, $count_arr ) ) {
				// カウント数だけ先に取得しておく
				$count_arr = $count_arr[1];

				// 「s:数字:」で区切って配列にする
				$serialize_val_arr = preg_split ( '/s:[0-9]+:/', $txt_org );
				array_shift ( $serialize_val_arr );

				$i = 0;
				foreach ( $serialize_val_arr as $row ) {
					if ( preg_match ( '/^((\\\)?")(.+?)((\\\)?";)/', $row, $result1 ) ) {
						// シリアライズ文字列の値部分を抽出
						$quotation1 = $result1[1];
						$serialize_val = $result1[3];
						$quotation2 = $result1[4];

						// 文字列を検索
						if ( preg_match_all ( '/' . $search_word_esc . '/', $serialize_val, $result2 ) ) {
							// もとの文字数
							$len_org = (int) $count_arr[$i];

							// 置換後
							$serialize_val_new = str_replace ( $search_word, $replace_word, $serialize_val );

							// 増加後の文字数
							// エスケープされた文字は1文字としてカウントする（バックスラッシュを除去）
							$serialize_val_new_count = str_replace ( '\\', '', $serialize_val_new );
							$len_new = strlen ( $serialize_val_new_count );

							// 検索
							$serialize_org =  's:' . $len_org . ':' . $quotation1 . $serialize_val_new . $quotation2;

							// 置換
							$serialize_replace =  's:' . $len_new . ':' . $quotation1 . $serialize_val_new . $quotation2;

							// 置き換える
							$txt =  str_replace ( $serialize_org, $serialize_replace, $txt );
						}
					}
					$i++;
				}
			}
		}
		return array ( $txt );
	}

	public function clone_test_db_connect_db( $db_name ) {
		try {
			$dsn = 'mysql:dbname=' . $db_name . ';host=' . DB_HOST . ';charset=' . DB_CHARSET;
			$options = array (
				PDO::MYSQL_ATTR_INIT_COMMAND => 'SET sql_mode=""'
			);

			$pdo = new PDO (
				$dsn,
				DB_USER,
				DB_PASSWORD,
				$options
			);
			$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			return $pdo;
		} catch ( PDOException $e ) {
			return 'Connection failed: ' . $e->getMessage();
		}
	}

	public function clone_test_db_front_page() {
		$test_site_address = filter_input ( INPUT_POST, 'test_site_address', FILTER_SANITIZE_SPECIAL_CHARS );
		$test_db_name = filter_input ( INPUT_POST, 'test_db_name', FILTER_SANITIZE_SPECIAL_CHARS );
		$overwrite = filter_input ( INPUT_POST, 'overwrite', FILTER_SANITIZE_NUMBER_INT );
		$clone_test_db_nonce = filter_input ( INPUT_POST, 'clone_test_db_nonce', FILTER_SANITIZE_STRING );

		// 末尾のスラッシュ、空白文字は取り除く
		if ( $test_site_address )
			$test_site_address = preg_replace ( '/(\s|\/)*$/', '', $test_site_address );

		if ( $clone_test_db_nonce && wp_verify_nonce ( $clone_test_db_nonce, 'clone_test_db_run' ) ) {
			if ( !$test_site_address )
				$this->error[] = __( 'Test site address is not set.', 'clone-test-db' );

			if ( !$test_db_name )
				$this->error[] = __( 'Test database name is not yet set.', 'clone-test-db' );

			if ( !$this->error ) {
				$search_word = get_bloginfo( 'url' );
				$replace_word = $test_site_address;
				$master_db_name = DB_NAME;

				$master_pdo = $this->clone_test_db_connect_db( $master_db_name );

				$test_pdo = $this->clone_test_db_connect_db( $test_db_name );
				if ( !is_object( $test_pdo ) ) {
					// 接続状況チェック
					wp_die( $test_pdo );
				}

				$sql = "SHOW TABLES";
				$tables = $master_pdo->query( $sql );
				$tables_arr = $tables->fetchAll( PDO::FETCH_NUM );

				// 同名のテーブルがあるか確認する
				if ( !$overwrite ) {
					foreach ( $tables_arr as $row ) {
						$table_name = $row[0];
						$sql = "SHOW TABLES LIKE '$table_name'";
						$result2 = $test_pdo->query( $sql );
						if ( $result2->rowCount() )
							$this->error[] = esc_html( $row[0] ) . __( ' does exist.', 'clone-test-db' );
					}
				}

				if ( !$this->error || $overwrite ) {
					// まずはそのままコピー
					foreach ( $tables_arr as $row ) {
						$table_name = $row[0];

						// 同名のテーブルはまず削除する
						$sql = "DROP TABLE IF EXISTS `$test_db_name`.`$table_name`";
						$test_pdo->query( $sql );

						$sql = "CREATE TABLE IF NOT EXISTS `$test_db_name`.`$table_name` LIKE `$master_db_name`.`$table_name`";
						$test_pdo->query( $sql );
						$sql = "INSERT INTO `$test_db_name`.`$table_name` SELECT * FROM `$master_db_name`.`$table_name`";
						$test_pdo->query( $sql );
					}

					// Site address が同じなら置換は行わない
					if ( $test_site_address != get_bloginfo( 'url' ) ) {
						foreach ( $tables_arr as $row ) {
							$table_name = $row[0];
							if ( $result2 = $test_pdo->query( "SELECT * FROM `$master_db_name`.`$table_name`" ) ) {
								while ( $arr = $result2->fetch( PDO::FETCH_ASSOC ) ) {
									if ( $str = implode ( $arr ) ) {
										// 検索ワードが含まれていたら
										if ( strpos ( $str, $search_word ) !== false ) {
											$unique_col = '';
											$unique_id = '';
											foreach ( $arr as $col_name => $val ) {
												if ( !$unique_col )
													$unique_col = $col_name;
												if ( !$unique_id )
													$unique_id = (int) $val;
												if ( strpos ( $val, $search_word ) !== false ) {
													$sql = "UPDATE `$table_name` SET $col_name = :val WHERE $unique_col = :id";
													$stmt = $test_pdo->prepare( $sql );

													// 置換
													list ( $new_val ) = $this->clone_test_db_replace_rec( $val, $search_word, $replace_word );

													$stmt->bindValue( ':val', $new_val );
													$stmt->bindValue( ':id', $unique_id, PDO::PARAM_INT );
													$stmt->execute();
												}
											}
										}
									}
								}
							}
						}
					}
					$this->updated[] = __( 'Successfully cloned the database!', 'clone-test-db' );
				}
			}
		}
?>
<div class="wrap" id="clone-test-db">
	<div id="icon-themes" class="icon32">&nbsp;</div><h2>Clone Test DB</h2>
<?php
		if ( $this->error ) {
			echo '<div class="error">';
			foreach ( $this->error as $msg ) {
				echo '<p>' . esc_html( $msg ) . '</p>';
			}
			echo '</div>';
		}
		if ( $this->updated ) {
			echo '<div class="updated">';
			foreach ( $this->updated as $msg ) {
				echo '<p>' . esc_html( $msg ) . '</p>';
			}
			echo '</div>';
		}
?>
	<p><?php echo __( 'Duplicate the table in another database and optimize it as a test site.', 'clone-test-db' ); ?><br>
	<?php echo __( '*To view the test site, you will need to transfer files.', 'clone-test-db' ); ?><br>
	<?php echo __( '*Sorry, multi-site is not supported.', 'clone-test-db' ); ?></p>
	<form method="post" action="?page=clone_test_db">
		<div class="clone-test-db__cols">
			<div class="clone-test-db__cols__col">
				<p class="clone-test-db__cols__hd"><?php echo __( 'Site address', 'clone-test-db' ); ?></p>
				<input size="50" type="text" value="<?php bloginfo( 'url' ); ?>" disabled />
				<p></P>
				<p class="clone-test-db__cols__hd"><?php echo __( 'Database name', 'clone-test-db' ); ?></p>
				<input size="50" type="text" value="<?php echo DB_NAME; ?>" disabled />
			</div>
			<div class="clone-test-db__cols__col">
				<span class="clone-test-db__cols__pc">&#8594;</span>
				<span class="clone-test-db__cols__sp">&#8595;</span>
			</div>
			<div class="clone-test-db__cols__col">
				<p class="clone-test-db__cols__hd"><label for="fld_test_site_address"><?php echo __( 'Test site address', 'clone-test-db' ); ?></label></p>
				<input name="test_site_address" size="50" type="text" value="<?php echo esc_attr( $test_site_address ); ?>" id="fld_test_site_address" />
				<p></P>
				<p class="clone-test-db__cols__hd"><label for="fld_test_db_name"><?php echo __( 'Test database name', 'clone-test-db' ); ?></label></p>
				<select name="test_db_name" id="fld_test_db_name">
					<option value=""></option>
<?php
// データベース名をすべて取得する
$db = new mysqli( DB_HOST, DB_USER, DB_PASSWORD );
$sql = "SHOW DATABASES";
$dbs = $db->query( $sql );
while ( $row = $dbs->fetch_row() ) {
	// コピー元と同じデータベースは除外
	if ( DB_NAME == $row[0] )
		continue;
?>
					<option value="<?php echo esc_attr ( $row[0] ); ?>"><?php echo esc_html ( $row[0] ); ?></option>
<?php
}
?>
				</select>
			</div>
		</div>
		<div class="clone-test-db__overwrite"><div>
			<p><label><input name="overwrite" type="checkbox" value="1" id="fld_overwrite" /> <?php echo __( 'Overwrite the table', 'clone-test-db' ); ?></label></p>
			<p><?php echo __( 'If there is even one table with the same name, the copy will not be done.<br>If you want to ignore it and replace the table, check the box.', 'clone-test-db' ); ?></p>
		</div></div>
		<p class="submit"><input type="submit" value="<?php echo __( 'Create a clone site', 'clone-test-db' ); ?>" class="button-primary">
		<?php echo wp_nonce_field( 'clone_test_db_run', 'clone_test_db_nonce' ); ?>
	</form>
</div>
<?php
	}
}
new clone_test_db();
