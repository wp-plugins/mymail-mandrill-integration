<?php
/*
Plugin Name: MyMail Mandrill Integration
Plugin URI: http://rxa.li/mymail
Description: Uses Mandrill to deliver emails for the MyMail Newsletter Plugin for WordPress.
This requires at least version 2.0 of the plugin
Version: 0.3.2
Author: revaxarts.com
Author URI: http://revaxarts.com
License: GPLv2 or later
*/


define('MYMAIL_MANDRILL_VERSION', '0.3.2');
define('MYMAIL_MANDRILL_REQUIRED_VERSION', '2.0');
define('MYMAIL_MANDRILL_ID', 'mandrill');
define('MYMAIL_MANDRILL_DOMAIN', 'mymail-mandrill');
define('MYMAIL_MANDRILL_DIR', WP_PLUGIN_DIR.'/mymail-mandrill-integration');
define('MYMAIL_MANDRILL_URI', plugins_url().'/mymail-mandrill-integration');
define('MYMAIL_MANDRILL_SLUG', 'mymail-mandrill-integration/mandrill.php');


class MyMailMandrill {

	
	public function __construct(){

		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->plugin_url = plugin_dir_url( __FILE__ );

		register_activation_hook( __FILE__, array(&$this, 'activate') );
		register_deactivation_hook( __FILE__, array(&$this, 'deactivate') );
		
		load_plugin_textdomain( 'mymail-mandrill', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );
		
		add_action( 'init', array( &$this, 'init'), 1 );
	}

	/**
	 * init function.
	 * 
	 * init the plugin
	 *
	 * @access public
	 * @return void
	 */
	public function init() {


		if (!defined('MYMAIL_VERSION') || version_compare(MYMAIL_MANDRILL_REQUIRED_VERSION, MYMAIL_VERSION, '>')) {

			add_action('admin_notices', array(&$this, 'notice'));
			
		} else {
		
			add_filter('mymail_delivery_methods', array(&$this, 'delivery_method'));
			add_action('mymail_deliverymethod_tab_mandrill', array(&$this, 'deliverytab'));
			
			add_filter('mymail_verify_options', array(&$this, 'verify_options'));

			if (mymail_option('deliverymethod') == MYMAIL_MANDRILL_ID) {

				add_action('mymail_initsend', array(&$this, 'initsend'));
				add_action('mymail_presend', array(&$this, 'presend'));
				add_action('mymail_dosend', array(&$this, 'dosend'));
				add_action('mymail_cron_worker', array(&$this, 'check_bounces'), -1);
				add_action('mymail_check_bounces', array(&$this, 'check_bounces'));

				add_filter('mymail_subscriber_errors', array(&$this, 'subscriber_errors'));
				add_action('mymail_section_tab_bounce', array(&$this, 'section_tab_bounce'));
			}

			add_action('admin_init', array(&$this, 'settings_scripts_styles'));
			add_action('mymail_mandrill_cron', array(&$this, 'getquota'));

		}
		
	}


	/**
	 * initsend function.
	 * 
	 * uses mymail_initsend hook to set initial settings
	 *
	 * @access public
	 * @param mixed $mailobject
	 * @return void
	 */
	public function initsend($mailobject) {


		if (mymail_option(MYMAIL_MANDRILL_ID.'_api') == 'smtp') {
		
			$port = mymail_option(MYMAIL_MANDRILL_ID.'_port', 25);

			$mailobject->mailer->Mailer = 'smtp';
			$mailobject->mailer->SMTPSecure = $port == 465 ? true : false;
			$mailobject->mailer->Host = 'smtp.mandrillapp.com';
			$mailobject->mailer->Port = $port;
			$mailobject->mailer->SMTPAuth = true;
			$mailobject->mailer->Username = mymail_option(MYMAIL_MANDRILL_ID.'_username');
			$mailobject->mailer->Password = mymail_option(MYMAIL_MANDRILL_ID.'_apikey');
			$mailobject->mailer->SMTPKeepAlive = true;
		
		} else {
		
			//disable dkim
			$mailobject->dkim = false;
		}
		
		(!defined('MYMAIL_DOING_CRON') && mymail_option(MYMAIL_MANDRILL_ID.'_backlog'))
			? mymail_notice(sprintf(__('You have %s mails in your Backlog! %s', MYMAIL_MANDRILL_DOMAIN), '<strong>'.mymail_option(MYMAIL_MANDRILL_ID.'_backlog').'</strong>', '<a href="http://eepurl.com/rvxGP" class="external">'.__('What is this?', MYMAIL_MANDRILL_DOMAIN).'</a>'), 'error', true, 'mandrill_backlog')
			: mymail_remove_notice('mandrill_backlog');

		
	}


	/**
	 * subscriber_errors function.
	 * 
	 * adds a subscriber error
	 * @access public
	 * @param mixed $mailobject
	 * @return $errors
	 */
	public function subscriber_errors($errors) {
		$errors[] = '[rejected]';
		return $errors;
	}


	/**
	 * presend function.
	 * 
	 * uses the mymail_presend hook to apply setttings before each mail
	 * @access public
	 * @param mixed $mailobject
	 * @return void
	 */
	 
	 
	public function presend($mailobject) {
		
		//use pre_send from the main class
		//need the raw email body to send so we use the same option
		$mailobject->pre_send();
		
		if($track = mymail_option(MYMAIL_MANDRILL_ID.'_track')) $mailobject->mailer->addCustomHeader('X-MC-Track', $track);
		if($subaccount = mymail_option(MYMAIL_MANDRILL_ID.'_subaccount')) $mailobject->mailer->addCustomHeader('X-MC-Subaccount', $subaccount);
		
	}


	/**
	 * dosend function.
	 * 
	 * uses the ymail_dosend hook and triggers the send
	 * @access public
	 * @param mixed $mailobject
	 * @return void
	 */
	public function dosend($mailobject) {
		
		if (mymail_option(MYMAIL_MANDRILL_ID.'_api') == 'smtp') {

			//use send from the main class
			$mailobject->do_send();
			
			
		} else {

			$mailobject->mailer->PreSend();
			$raw_message = $mailobject->mailer->GetSentMIMEMessage();
			
			$timeout = 15;
			
			$response = $this->do_call('messages/send-raw', array(
				'raw_message' => $raw_message,
				'from_email' => $mailobject->from,
				'from_name' => $mailobject->from_name,
				'to' => $mailobject->to,
				'async' => defined('MYMAIL_DOING_CRON'),
				'ip_pool' => null,
				'return_path_domain' => null,
			), true, $timeout);

			if(is_wp_error($response)){
			
				$mailobject->set_error($response->get_error_message());
				$mailobject->sent = false;
				
			} else {
				
				$response = $response[0];
				if($response->status == 'sent' || $response->status == 'queued'){
					$mailobject->sent = true;
				}else{
					if(in_array($response->reject_reason, array('soft-bounce'))){
					
						//softbounced already so
						$hash = $mailobject->headers['X-MyMail'];
						$camp = $mailobject->headers['X-MyMail-Campaign'];
							
						if($camp && $hash){
							
							$subscriber = mymail('subscribers')->get_by_hash($hash);
						
							$deleteresponse = $this->do_call('rejects/delete', array(
								'email' => $subscriber->email,
								'subaccount' => mymail_option(MYMAIL_MANDRILL_ID.'_subaccount')
							), true);
						
							if(isset($deleteresponse->deleted) && $deleteresponse->deleted){
							
								$this->dosend($mailobject);
						
							}else{
							
								$mailobject->sent = true;
								
							}
							
							
						}else{
						
							$mailobject->set_error('['.$response->status.'] '.$response->reject_reason);
							$mailobject->sent = false;
							
						}
							
						
					}else{
						$mailobject->set_error('['.$response->status.'] '.$response->reject_reason);
						$mailobject->sent = false;
					}
				}
			}
		}
		
	}


	/**
	 * check_bounces function.
	 * 
	 * checks for bounces and reset them if needed
	 * @access public
	 * @return void
	 */
	public function check_bounces() {

			if ( get_transient( 'mymail_check_bounces_lock' ) ) return false;
			
			//check bounces only every five minutes
			set_transient( 'mymail_check_bounces_lock', true, mymail_option('bounce_check', 5)*60 );

			$subaccount = mymail_option(MYMAIL_MANDRILL_ID.'_subaccount', NULL);
			
			$response = $this->do_call('rejects/list', array('subaccount' => $subaccount), true);

			if(is_wp_error($response)){
			
				$response->get_error_message();
				//Stop if there was an error
				return false;
				
			}
			
			if(!empty($response)){
			
				//only the first 100
				$count = 100;
				foreach(array_slice($response, 0, $count) as $subscriberdata){
				
					$subscriber = mymail('subscribers')->get_by_mail($subscriberdata->email);

					//only if user exists
					if($subscriber){

						$reseted = false;
						$campaigns = mymail('subscribers')->get_sent_campaigns($subscriber->ID);

						foreach($campaigns as $i => $campaign){

							//only campaign which have been started maximum a day ago or the last 10 campaigns
							if($campaign->timestamp-strtotime($subscriberdata->created_at)+60*1440 < 0 || $i >= 10) break;

							if(mymail('subscribers')->bounce($subscriber->ID, $campaign->campaign_id, $subscriberdata->reason == 'hard-bounce')){
								$response = $this->do_call('rejects/delete', array(
									'email' => $subscriberdata->email,
									'subaccount' => $subaccount
								), true);
								$reseted = isset($response->deleted) && $response->deleted;
							}

						}

						if(!$reseted){
							$response = $this->do_call('rejects/delete', array(
								'email' => $subscriberdata->email,
								'subaccount' => $subaccount
							), true);
							$reseted = isset($response->deleted) && $response->deleted;
						}

						
					}else{
						//remove user from the list
						$response = $this->do_call('rejects/delete', array(
							'email' => $subscriberdata->email,
							'subaccount' => $subaccount
						));
						$count++;
					}
				}
			}
			
	}




	/**
	 * do_call function.
	 * 
	 * makes a post request to the mandrill endpoint and returns the result
	 * @access public
	 * @param mixed $path
	 * @param array $data (default: array())
	 * @param bool $bodyonly (default: false)
	 * @param int $timeout (default: 5)
	 * @return void
	 */
	public function do_call($path, $data = array(), $bodyonly = false, $timeout = 5) {
		
		$url = 'http://mandrillapp.com/api/1.0/'.$path.'.json';
		if(is_bool($data)){
			$bodyonly = $data;
			$data = array();
		}
		$data = wp_parse_args($data, array('key' => mymail_option(MYMAIL_MANDRILL_ID.'_apikey')));
		
		$response = wp_remote_post( $url, array(
			'timeout' => $timeout,
			'sslverify' => false,
			'body' => $data
		));
		
		if(is_wp_error($response)){
		
			return $response;

		}
		
		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response));
		
		if($code != 200) return new WP_Error($body->name, $body->message);
		
		if($bodyonly) return $body;
		
		return (object) array(
			'code' => $code,
			'headers' => wp_remote_retrieve_headers($response),
			'body' => $body,
		);
		
		
	}


	/**
	 * getquota function.
	 * 
	 * returns the quota of the account or an WP_error if credentials are wrong
	 * @access public
	 * @param bool $save (default: true)
	 * @param string $apikey (default: NULL)
	 * @param string $subaccount (default: NULL)
	 * @return void
	 */
	public function getquota($save = true, $apikey = NULL, $subaccount = NULL) {

		$apikey = (!is_null($apikey)) ? $apikey : mymail_option(MYMAIL_MANDRILL_ID.'_apikey');
		$subaccount = (!is_null($subaccount)) ? $subaccount : mymail_option(MYMAIL_MANDRILL_ID.'_subaccount', NULL);
		
		$response = $this->do_call('users/info', array('key' => $apikey), true);
		
		if (is_wp_error($response)) return $response;

		$limits = array(
			'daily' => $response->hourly_quota*24,
			'hourly' => $response->hourly_quota,
			'sent' => 0,
			'sent_total' => $response->stats->all_time->sent,
			'backlog' => $response->backlog,
		);
		
		//if a subaccount is use change the sent value but keep the quota of the main account if it's less
		if($subaccount){
			$response = $this->do_call('subaccounts/info', array('key' => $apikey, 'id' => $subaccount), true);
			if (is_wp_error($response)) return $response;
			$limits['hourly'] = min($limits['hourly'], $response->hourly_quota);
			$limits['sent'] = $response->sent_hourly;
			$limits['daily'] = $response->hourly_quota*24;
		}
		
		
		if ($save) $this->update_limits($limits);
		
		return $limits;

	}


	/**
	 * delivery_method function.
	 * 
	 * add the delivery method to the options
	 * @access public
	 * @param mixed $delivery_methods
	 * @return void
	 */
	public function delivery_method($delivery_methods) {
		$delivery_methods[MYMAIL_MANDRILL_ID] = 'Mandrill';
		return $delivery_methods;
	}


	/**
	 * deliverytab function.
	 * 
	 * the content of the tab for the options
	 * @access public
	 * @return void
	 */
	public function deliverytab() {

		$verified = mymail_option(MYMAIL_MANDRILL_ID.'_verified');
		
	?>
		<table class="form-table">
			<?php if(!$verified) : ?>
			<tr valign="top">
				<th scope="row">&nbsp;</th>
				<td><p class="description"><?php echo sprintf(__('You need a %s to use this service!', MYMAIL_MANDRILL_DOMAIN), '<a href="https://mandrill.com/signup/" class="external">Mandrill Account</a>'); ?></p>
				</td>
			</tr>
			<?php endif; ?>
			<tr valign="top">
				<th scope="row"><?php _e('Mandrill Username' , MYMAIL_MANDRILL_DOMAIN) ?></th>
				<td><input type="text" name="mymail_options[<?php echo MYMAIL_MANDRILL_ID ?>_username]" value="<?php echo esc_attr(mymail_option(MYMAIL_MANDRILL_ID.'_username')); ?>" class="regular-text"></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Mandrill API Key' , MYMAIL_MANDRILL_DOMAIN) ?></th>
				<td><input type="password" name="mymail_options[<?php echo MYMAIL_MANDRILL_ID ?>_apikey]" value="<?php echo esc_attr(mymail_option(MYMAIL_MANDRILL_ID.'_apikey')); ?>" class="regular-text" placeholder="xxxxxxxxxxxxxxxxxxxxxx"></td>
			</tr>
			<tr valign="top">
				<th scope="row">&nbsp;</th> 
				<td>
					<img src="<?php echo MYMAIL_URI . 'assets/img/icons/'.($verified ? 'green' : 'red').'_2x.png'?>" width="16" height="16">
					<?php echo ($verified) ? __('Your credentials are ok!', MYMAIL_MANDRILL_DOMAIN) : __('Your credentials are WRONG!', MYMAIL_MANDRILL_DOMAIN)?>
					<input type="hidden" name="mymail_options[<?php echo MYMAIL_MANDRILL_ID ?>_verified]" value="<?php echo $verified?>">
				</td>
			</tr>
		</table>
		<div <?php if (!$verified) echo ' style="display:none"' ?>>
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php _e('Send Emails with' , MYMAIL_MANDRILL_DOMAIN) ?></th>
				<td>
				<select name="mymail_options[<?php echo MYMAIL_MANDRILL_ID ?>_api]" class="mymail-mandrill-api">
					<option value="web" <?php selected(mymail_option( MYMAIL_MANDRILL_ID.'_api'), 'web')?>>WEB API</option>
					<option value="smtp" <?php selected(mymail_option( MYMAIL_MANDRILL_ID.'_api'), 'smtp')?>>SMTP API</option>
				</select>
				</td>
			</tr>
		</table>
		<div class="mandrill-tab-smtp" <?php if (mymail_option( MYMAIL_MANDRILL_ID.'_api') != 'smtp') echo ' style="display:none"' ?>>
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php _e('SMTP Port' , MYMAIL_MANDRILL_DOMAIN) ?></th>
				<td>
				<select name="mymail_options[<?php echo MYMAIL_MANDRILL_ID ?>_port]">
					<option value="25"<?php selected(mymail_option(MYMAIL_MANDRILL_ID.'_port'), 25); ?>>25</option>
					<option value="465"<?php selected(mymail_option(MYMAIL_MANDRILL_ID.'_port'), 465); ?>>465 <?php _e('with' , MYMAIL_MANDRILL_DOMAIN) ?> SSL</option>
					<option value="587"<?php selected(mymail_option(MYMAIL_MANDRILL_ID.'_port'), 587); ?>>587</option>
					<option value="2525"<?php selected(mymail_option(MYMAIL_MANDRILL_ID.'_port'), 2525); ?>>2525</option>
				</select></td>
			</tr>
		</table>
		</div>
		<?php if (mymail_option('deliverymethod') == MYMAIL_MANDRILL_ID) : ?>
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php _e('Use subaccount' , MYMAIL_MANDRILL_DOMAIN) ?></th>
				<td>
				<select name="mymail_options[<?php echo MYMAIL_MANDRILL_ID ?>_subaccount]">
					<option value=""<?php selected(mymail_option(MYMAIL_MANDRILL_ID.'_subaccount'), 0); ?>><?php _e('none', MYMAIL_MANDRILL_DOMAIN); ?></option>
				<?php 
						$subaccounts = $this->get_subaccounts(); 
						foreach($subaccounts as $account){
							echo '<option value="'.$account->id.'" '.selected(mymail_option(MYMAIL_MANDRILL_ID.'_subaccount'), $account->id, true).'>'.$account->name.($account->status != 'active' ? ' ('.$account->status.')' : '').'</option>';
						}
				?>
				</select> <span class="description"><?php echo sprintf(__('Create new subaccounts on %s', MYMAIL_MANDRILL_DOMAIN), '<a href="https://mandrillapp.com/subaccounts" class="external">'.__('your Mandrill Dashboard', MYMAIL_MANDRILL_DOMAIN).'</a>'); ?></span></td>
			</tr>
		</table>
		<?php endif; ?>
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php _e('Track in Mandrill' , MYMAIL_MANDRILL_DOMAIN) ?></th>
				<td>
				<select name="mymail_options[<?php echo MYMAIL_MANDRILL_ID ?>_track]">
					<option value="0"<?php selected(mymail_option(MYMAIL_MANDRILL_ID.'_track'), 0); ?>><?php _e('Account defaults', MYMAIL_MANDRILL_DOMAIN); ?></option>
					<option value="none"<?php selected(mymail_option(MYMAIL_MANDRILL_ID.'_track'), 'none'); ?>><?php _e('none', MYMAIL_MANDRILL_DOMAIN); ?></option>
					<option value="opens"<?php selected(mymail_option(MYMAIL_MANDRILL_ID.'_track'), 'opens'); ?>><?php _e('opens', MYMAIL_MANDRILL_DOMAIN); ?></option>
					<option value="clicks"<?php selected(mymail_option(MYMAIL_MANDRILL_ID.'_track'), 'clicks'); ?>><?php _e('clicks', MYMAIL_MANDRILL_DOMAIN); ?></option>
					<option value="opens,clicks"<?php selected(mymail_option(MYMAIL_MANDRILL_ID.'_track'), 'opens,clicks'); ?>><?php _e('opens and clicks', MYMAIL_MANDRILL_DOMAIN); ?></option>
				</select> <span class="description"><?php _e('Track opens and clicks in Mandrill as well', MYMAIL_MANDRILL_DOMAIN); ?></span></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Update Limits' , MYMAIL_MANDRILL_DOMAIN) ?></th>
				<td><label><input type="checkbox" name="mymail_options[<?php echo MYMAIL_MANDRILL_ID ?>_autoupdate]" value="1" <?php checked(mymail_option( MYMAIL_MANDRILL_ID.'_autoupdate' ), true)?>> <?php _e('auto update send limits (recommended)', MYMAIL_MANDRILL_DOMAIN); ?> </label></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('max emails at once' , MYMAIL_MANDRILL_DOMAIN) ?></th>
				<td><input type="text" name="mymail_options[<?php echo MYMAIL_MANDRILL_ID ?>_send_at_once]" value="<?php echo esc_attr(mymail_option(MYMAIL_MANDRILL_ID.'_send_at_once', 100)); ?>" class="small-text">
				<span class="description"><?php _e('define the most highest value for auto calculated send value to prevent server timeouts', MYMAIL_MANDRILL_DOMAIN); ?></span>
				</td>
			</tr>
		</table>
		<input type="hidden" name="mymail_options[<?php echo MYMAIL_MANDRILL_ID ?>_backlog]" value="<?php echo mymail_option( MYMAIL_MANDRILL_ID.'_backlog', 0 ) ?>">
		</div>

	<?php

	}


	/**
	 * section_tab_bounce function.
	 * 
	 * displays a note on the bounce tab (MyMail >= 1.6.2)
	 * @access public
	 * @param mixed $options
	 * @return void
	 */
	public function section_tab_bounce() {
	?>
		<div class="error inline"><p><strong><?php _e('Bouncing is handled by Mandrill so all your settings will be ignored', MYMAIL_MANDRILL_DOMAIN); ?></strong></p></div>

	<?php
	}


	/**
	 * verify_options function.
	 * 
	 * some verification if options are saved
	 * @access public
	 * @param mixed $options
	 * @return void
	 */
	public function verify_options($options) {

		if ( $timestamp = wp_next_scheduled( 'mymail_mandrill_cron' ) ) {
			wp_unschedule_event($timestamp, 'mymail_mandrill_cron' );
		}
		//only if deleivermethod is mandrill
		if ($options['deliverymethod'] == MYMAIL_MANDRILL_ID) {

			if (($options[MYMAIL_MANDRILL_ID.'_username'] && $options[MYMAIL_MANDRILL_ID.'_apikey'])) {

				$limits = $this->getquota(false, $options[MYMAIL_MANDRILL_ID.'_apikey'], $options[MYMAIL_MANDRILL_ID.'_subaccount']);
				
				if ( is_wp_error($limits) ) {
				
					add_settings_error( 'mymail_options', 'mymail_options', __('An error occurred:<br>', MYMAIL_MANDRILL_DOMAIN).$limits->get_error_message() );
					$options[MYMAIL_MANDRILL_ID.'_verified'] = false;
					
				} else {
				
					$options[MYMAIL_MANDRILL_ID.'_verified'] = true;
					
					if($limits && isset($options[MYMAIL_MANDRILL_ID.'_autoupdate'])){
						
						$this->update_limits($limits, false);
						
						$options['send_limit'] = $limits['hourly'];
						$options['send_period'] = 1;
						$options['send_delay'] = 0;
						$options['send_at_once'] = min($options[MYMAIL_MANDRILL_ID.'_send_at_once'], max(1, floor($limits['daily']/(1440/$options['interval']))));
						
						$options[MYMAIL_MANDRILL_ID.'_backlog'] = $limits['backlog'];
						
						add_settings_error( 'mymail_options', 'mymail_options', __('Send limit has been adjusted to your Mandrill limits', MYMAIL_MANDRILL_VERSION), 'updated' );
					}
					
				}

			}
			if(isset($options[MYMAIL_MANDRILL_ID.'_autoupdate'])){
				if ( !wp_next_scheduled( 'mymail_mandrill_cron' ) ) {
					wp_schedule_event( time()+3600, 'hourly', 'mymail_mandrill_cron');
				}
			}
			
			if(function_exists( 'fsockopen' ) && mymail_option(MYMAIL_MANDRILL_ID.'_api') == 'smtp'){
				$host = 'smtp.mandrillapp.com';
				$port = $options[MYMAIL_MANDRILL_ID.'_port'];
				$conn = fsockopen($host, $port, $errno, $errstr, 5);
				
				if(is_resource($conn)){
					
					fclose($conn);
					
				}else{
					
					add_settings_error( 'mymail_options', 'mymail_options', sprintf(__('Not able to use Mandrill via SMTP cause of the blocked port %s! Please try a different port, send with the WEB API or choose a different delivery method!', MYMAIL_MANDRILL_DOMAIN), $port) );
					
				}
			}
			

		}
		
		return $options;
	}


	/**
	 * get_subaccounts function.
	 * 
	 * get a list of subaccounts
	 * @access public
	 * @return void
	 */
	public function get_subaccounts() {

		if(!($accounts = get_transient('mymail_mandrill_subaccounts'))){
			$accounts = $this->do_call('subaccounts/list', true);
			if(!is_wp_error($accounts)){
				set_transient('mymail_mandrill_subaccounts', $accounts, 3600);
			}else{
				$accounts = array();
			}
		}
		
		return $accounts;
		
	}


	/**
	 * update_limits function.
	 * 
	 * Update the limits
	 * @access public
	 * @return void
	 */
	public function update_limits($limits, $update = true) {
		if($update){
			mymail_update_option('send_limit', $limits['hourly']);
			mymail_update_option('send_period', 1);
			mymail_update_option('send_delay', 0);
			mymail_update_option('send_at_once', min(mymail_option(MYMAIL_MANDRILL_ID.'_send_at_once', 100),max(1, floor($limits['daily']/(1440/mymail_option('interval'))))));
			mymail_update_option(MYMAIL_MANDRILL_ID.'_backlog', $limits['backlog']);
		}
		($limits['backlog'])
			? mymail_notice(sprintf(__('You have %s mails in your Backlog! %s', MYMAIL_MANDRILL_DOMAIN), '<strong>'.$limits['backlog'].'</strong>', '<a href="http://eepurl.com/rvxGP" class="external">'.__('What is this?', MYMAIL_MANDRILL_DOMAIN).'</a>'), 'error', true, 'mandrill_backlog')
			: mymail_remove_notice('mandrill_backlog');
		
		if(!get_transient('_mymail_send_period_timeout')){
			set_transient('_mymail_send_period_timeout', true, $options['send_period']*3600);
		}
		update_option('_transient__mymail_send_period_timeout', $limits['sent'] > 0);
		update_option('_transient__mymail_send_period', $limits['sent']);
	}


	/**
	 * notice function.
	 * 
	 * Notice if MyMail is not available
	 * @access public
	 * @return void
	 */
	public function notice() {
	?>
	<div id="message" class="error">
		<p>
		<strong>Mandrill integration for MyMail</strong> requires the <a href="http://rxa.li/mymail?utm_source=Mandrill+integration+for+MyMail">MyMail Newsletter Plugin</a>, at least version <strong><?php echo MYMAIL_MANDRILL_REQUIRED_VERSION?></strong>. Plugin deactivated.
		</p>
	</div>
	<?php
	}


	/**
	 * settings_scripts_styles function.
	 * 
	 * some scripts are needed
	 * @access public
	 * @return void
	 */
	public function settings_scripts_styles() {
		global $pagenow;
		
		if($pagenow == 'options-general.php' && isset($_REQUEST['page']) && $_REQUEST['page'] == 'newsletter-settings'){

			wp_register_script('mymail-mandrill-settings-script', MYMAIL_MANDRILL_URI . '/js/script.js', array('jquery'), MYMAIL_MANDRILL_VERSION);
			wp_enqueue_script('mymail-mandrill-settings-script');
			
		}

	}


	/**
	 * activation function.
	 * 
	 * activate function
	 * @access public
	 * @return void
	 */
	public function activate() {

		if (defined('MYMAIL_VERSION') && version_compare(MYMAIL_MANDRILL_REQUIRED_VERSION, MYMAIL_VERSION, '<=')) {
			mymail_notice(sprintf(__('Change the delivery method on the %s!', MYMAIL_MANDRILL_DOMAIN), '<a href="options-general.php?page=newsletter-settings&mymail_remove_notice=mymail_delivery_method#delivery">Settings Page</a>'), '', false, 'delivery_method');
			if ( !wp_next_scheduled( 'mymail_mandrill_cron' ) ) {
				wp_schedule_event( time(), 'hourly', 'mymail_mandrill_cron');
			}
		}
		
	}


	/**
	 * deactivation function.
	 * 
	 * deactivate function
	 * @access public
	 * @return void
	 */
	public function deactivate() {

		if (defined('MYMAIL_VERSION') && version_compare(MYMAIL_MANDRILL_REQUIRED_VERSION, MYMAIL_VERSION, '<=')) {
			if(mymail_option('deliverymethod') == MYMAIL_MANDRILL_ID){
				mymail_update_option('deliverymethod', 'simple');
				mymail_notice(sprintf(__('Change the delivery method on the %s!', MYMAIL_MANDRILL_DOMAIN), '<a href="options-general.php?page=newsletter-settings&mymail_remove_notice=mymail_delivery_method#delivery">Settings Page</a>'), '', false, 'delivery_method');
			}
			
			if ( $timestamp = wp_next_scheduled( 'mymail_mandrill_cron' ) ) {
				wp_unschedule_event($timestamp, 'mymail_mandrill_cron' );
			}
		}
		
	}

}

new MyMailMandrill();
?>