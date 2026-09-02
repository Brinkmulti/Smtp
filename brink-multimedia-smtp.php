<?php
/**
 * Plugin Name: Brink Multimedia SMTP
 * Plugin URI: https://www.brink-multimedia.nl
 * Description: Brink Multimedia SMTP Sender for Wordpress inclusief Microsoft OAuth en uitgebreide server logging.
 * Version: 2.3
 * Author: Brink Multimedia
 * Author URI: https://www.brink-multimedia.nl
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Voorkom directe toegang
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Globale variabele om server communicatie op te vangen
global $brink_smtp_global_debug;
$brink_smtp_global_debug = '';

/**
 * FEATURE: GitHub Auto-Updater (Het Radartje)
 */
add_filter( 'pre_set_site_transient_update_plugins', 'brink_smtp_github_check_update' );
function brink_smtp_github_check_update( $transient ) {
    if ( empty( $transient->checked ) ) {
        return $transient;
    }

    $plugin_slug = plugin_basename( __FILE__ );
    $huidige_versie = $transient->checked[$plugin_slug] ?? '2.2';
    
    // Vraag aan GitHub of er een nieuwe versie is
    $response = wp_remote_get( 'https://api.github.com/repos/Brinkmulti/Smtp/releases/latest', array(
        'headers' => array( 'Accept' => 'application/vnd.github.v3+json' )
    ) );

    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
        $data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( isset( $data->tag_name ) ) {
            $github_versie = ltrim( $data->tag_name, 'v' ); // Haal de 'v' weg (v2.3 wordt 2.3)

            // Als de versie op GitHub hoger is, activeer de update knop in WordPress!
            if ( version_compare( $huidige_versie, $github_versie, '<' ) ) {
                $plugin_info = new stdClass();
                $plugin_info->slug = current( explode('/', $plugin_slug) );
                $plugin_info->plugin = $plugin_slug;
                $plugin_info->new_version = $github_versie;
                $plugin_info->package = $data->zipball_url;
                $plugin_info->url = $data->html_url;

                $transient->response[$plugin_slug] = $plugin_info;
            }
        }
    }
    return $transient;
}

// Zorgt ervoor dat WordPress de plugin-map niet verkeerd hernoemt tijdens het updaten
add_filter( 'upgrader_source_selection', 'brink_smtp_github_fix_mapnaam', 10, 3 );
function brink_smtp_github_fix_mapnaam( $source, $remote_source, $upgrader ) {
    global $wp_filesystem;
    if ( isset( $upgrader->skin->plugin_info ) && $upgrader->skin->plugin_info['Name'] === 'Brink Multimedia SMTP' ) {
        $juiste_mapnaam = dirname( plugin_basename( __FILE__ ) );
        $nieuwe_bron = trailingslashit( $remote_source ) . $juiste_mapnaam;
        if ( $wp_filesystem->move( $source, $nieuwe_bron ) ) {
            return trailingslashit( $nieuwe_bron );
        }
    }
    return $source;
}

/**
 * FEATURE 1: Forceer Afzender E-mail en Naam
 */
add_filter( 'wp_mail_from', 'mijn_smtp_force_from_email', 999 );
function mijn_smtp_force_from_email( $email ) {
    $opties = get_option( 'mijn_smtp_options', array() );
    if ( !empty($opties['force_from_email']) && !empty($opties['from_email']) ) {
        return $opties['from_email'];
    }
    return $email;
}

add_filter( 'wp_mail_from_name', 'mijn_smtp_force_from_name', 999 );
function mijn_smtp_force_from_name( $name ) {
    $opties = get_option( 'mijn_smtp_options', array() );
    if ( !empty($opties['force_from_name']) && !empty($opties['from_name']) ) {
        return $opties['from_name'];
    }
    return $name;
}

/**
 * FEATURE 2: Dashboard Widget
 */
add_action('wp_dashboard_setup', 'mijn_smtp_dashboard_widget_setup');
function mijn_smtp_dashboard_widget_setup() {
    wp_add_dashboard_widget('mijn_smtp_dashboard_widget', 'Brink SMTP Statistieken', 'mijn_smtp_dashboard_widget_render');
}
function mijn_smtp_dashboard_widget_render() {
    $huidige_teller = (int) get_option( 'mijn_smtp_wekelijkse_teller', 0 );
    echo "<p>Er zijn deze week <strong>{$huidige_teller}</strong> e-mails succesvol verzonden via jouw SMTP instellingen.</p>";
    echo "<a href='" . esc_url(admin_url('options-general.php?page=mijn-smtp-instellingen')) . "' class='button button-primary'>Bekijk Logboek & Instellingen</a>";
}

/**
 * FEATURE 3: Microsoft Token Expiry Warning
 */
add_action('admin_notices', 'mijn_smtp_check_expiry_notice');
function mijn_smtp_check_expiry_notice() {
    $opties = get_option( 'mijn_smtp_options', array() );
    if ( !empty($opties['methode']) && $opties['methode'] === 'microsoft' && !empty($opties['ms_secret_expiry']) ) {
        $expiry = strtotime($opties['ms_secret_expiry']);
        $now = current_time('timestamp');
        $diff = $expiry - $now;
        $days = floor($diff / DAY_IN_SECONDS);
        
        if ( $days <= 30 && $days >= 0 ) {
            echo "<div class='notice notice-warning'><p>⚠️ <strong>Brink Multimedia SMTP:</strong> Let op! Je Microsoft Client Secret verloopt over <strong>{$days} dagen</strong>. Maak op tijd een nieuwe aan in Azure (en werk de datum hier bij).</p></div>";
        } elseif ( $days < 0 ) {
            echo "<div class='notice notice-error'><p>❌ <strong>Brink Multimedia SMTP:</strong> Je Microsoft Client Secret is <strong>verlopen</strong>! Mailverkeer is mogelijk onderbroken. Update dit direct.</p></div>";
        }
    }
}

/**
 * 1. Voeg een menu-item toe aan het WordPress dashboard
 */
add_action( 'admin_menu', 'mijn_smtp_menu' );
function mijn_smtp_menu() {
    add_options_page(
        'Mijn SMTP Instellingen',
        'Mijn SMTP',
        'manage_options',
        'mijn-smtp-instellingen',
        'mijn_smtp_instellingen_pagina'
    );
}

/**
 * 2. Registreer de instellingen
 */
add_action( 'admin_init', 'mijn_smtp_registreer_instellingen' );
function mijn_smtp_registreer_instellingen() {
    register_setting( 'mijn_smtp_settings_group', 'mijn_smtp_options' );
}

/**
 * 2b. Callback afhandeling voor Microsoft OAuth
 */
add_action( 'admin_init', 'mijn_smtp_handle_oauth_callback' );
function mijn_smtp_handle_oauth_callback() {
    // Luister naar de terugkeer van Microsoft
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'mijn-smtp-instellingen' && isset( $_GET['code'] ) && isset( $_GET['state'] ) && $_GET['state'] === 'authorize_ms' ) {
        $opties = get_option( 'mijn_smtp_options', array() );
        $tenant = !empty($opties['ms_tenant_id']) ? $opties['ms_tenant_id'] : 'common';
        
        $url = "https://login.microsoftonline.com/" . $tenant . "/oauth2/v2.0/token";
        $redirect_uri = admin_url( 'options-general.php?page=mijn-smtp-instellingen' );
        
        $body = array(
            'client_id'     => $opties['ms_client_id'],
            'client_secret' => $opties['ms_client_secret'],
            'code'          => sanitize_text_field( $_GET['code'] ),
            'redirect_uri'  => $redirect_uri,
            'grant_type'    => 'authorization_code',
        );

        $response = wp_remote_post( $url, array( 'body' => $body ) );
        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $data['refresh_token'] ) ) {
                $opties['ms_refresh_token'] = $data['refresh_token'];
                update_option( 'mijn_smtp_options', $opties );
                
                wp_safe_redirect( admin_url( 'options-general.php?page=mijn-smtp-instellingen&oauth=success' ) );
                exit;
            }
        }
        wp_safe_redirect( admin_url( 'options-general.php?page=mijn-smtp-instellingen&oauth=failed' ) );
        exit;
    }

    // Luister naar het loskoppelen
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'mijn-smtp-instellingen' && isset( $_GET['disconnect_ms'] ) ) {
        $opties = get_option( 'mijn_smtp_options', array() );
        unset( $opties['ms_refresh_token'] );
        update_option( 'mijn_smtp_options', $opties );
        wp_safe_redirect( admin_url( 'options-general.php?page=mijn-smtp-instellingen' ) );
        exit;
    }
}

/**
 * 3. Ontwerp van de instellingenpagina in het dashboard
 */
function mijn_smtp_instellingen_pagina() {
    global $brink_smtp_global_debug;

    // --- BEGIN: TEST E-MAIL AFHANDELING ---
    if ( isset( $_POST['mijn_smtp_test_submit'] ) && current_user_can( 'manage_options' ) ) {
        $to = sanitize_email( $_POST['mijn_smtp_test_email'] );
        
        // Vang fout direct af voor UI
        add_action( 'wp_mail_failed', function( $error ) {
            global $brink_smtp_live_error;
            $brink_smtp_live_error = $error->get_error_message();
        } );

        ob_start();
        $verzonden = wp_mail( $to, 'Test E-mail via Brink SMTP', 'Gefeliciteerd! Als je dit leest, is de koppeling succesvol.' );
        ob_get_clean();
        
        if ( $verzonden ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Test e-mail succesvol verzonden naar <strong>' . esc_html( $to ) . '</strong>!</p></div>';
        } else {
            global $brink_smtp_live_error;
            echo '<div class="notice notice-error is-dismissible" style="padding-bottom: 10px;">';
            echo '<p>❌ <strong>Fout bij verzenden:</strong> ' . esc_html( $brink_smtp_live_error ?: 'Onbekende fout' ) . '</p>';
            
            $ms_error = get_transient( 'mijn_smtp_ms_token_error' );
            if ( $ms_error ) {
                echo '<div style="margin-top: 10px; padding: 10px; border-left: 4px solid #d63638; background: #fff;">';
                echo '<strong>⚠️ Microsoft OAuth Token Fout (Je mag niet inloggen):</strong><br>';
                echo '<code>' . esc_html( $ms_error ) . '</code><br>';
                echo '<em>Oplossing: Controleer in Azure of je op "Beheerderstoestemming verlenen" hebt geklikt.</em>';
                echo '</div>';
            }
            
            echo '<p style="color: #d63638; font-weight:bold; margin-top: 10px;">De server heeft de verbinding geweigerd. Bekijk het Logboek hieronder voor de specifieke code.</p>';
            echo '</div>';
            
            delete_transient( 'mijn_smtp_ms_token_error' );
        }
    }
    // --- EINDE: TEST E-MAIL AFHANDELING ---

    $opties = get_option( 'mijn_smtp_options', array() );
    $methode  = isset( $opties['methode'] ) ? $opties['methode'] : 'standaard';
    $from     = isset( $opties['from_email'] ) ? $opties['from_email'] : get_option('admin_email');
    $fromnaam = isset( $opties['from_name'] ) ? $opties['from_name'] : get_bloginfo('name');
    
    $huidige_teller = (int) get_option( 'mijn_smtp_wekelijkse_teller', 0 );
    ?>
    <div class="wrap">
        <h2>Brink Multimedia SMTP Instellingen</h2>

        <div style="background: #fff; padding: 15px; border-left: 4px solid #2271b1; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px;">
            <h3 style="margin-top: 0;">📊 Wekelijkse Statistieken</h3>
            <p>Er zijn deze week <strong><?php echo $huidige_teller; ?></strong> e-mails verzonden via deze plugin. <br>
            <em>(Elke maandag ontvangt de beheerder hier een overzicht van en wordt deze teller gereset).</em></p>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'mijn_smtp_settings_group' ); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Afzender E-mailadres</th>
                    <td><input type="email" name="mijn_smtp_options[from_email]" value="<?php echo esc_attr( $from ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">Afzender Naam</th>
                    <td><input type="text" name="mijn_smtp_options[from_name]" value="<?php echo esc_attr( $fromnaam ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">Forceer Afzender instellingen</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mijn_smtp_options[force_from_email]" value="1" <?php checked( !empty($opties['force_from_email']) ); ?> />
                            Forceer dit e-mailadres voor <strong>alle</strong> plugins (Aanbevolen)
                        </label>
                        <br>
                        <label style="margin-top: 5px; display: inline-block;">
                            <input type="checkbox" name="mijn_smtp_options[force_from_name]" value="1" <?php checked( !empty($opties['force_from_name']) ); ?> />
                            Forceer deze afzendernaam voor <strong>alle</strong> plugins
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Kies je Mailer</th>
                    <td>
                        <select name="mijn_smtp_options[methode]" id="mijn_smtp_methode">
                            <option value="standaard" <?php selected( $methode, 'standaard' ); ?>>Standaard SMTP (Brabix / Eigen Server)</option>
                            <option value="microsoft" <?php selected( $methode, 'microsoft' ); ?>>Microsoft Outlook / Office 365 (OAuth 2.0)</option>
                        </select>
                    </td>
                </tr>
            </table>

            <hr>

            <!-- STANDAARD SMTP -->
            <div id="sectie_standaard" style="<?php echo $methode === 'standaard' ? 'display:block;' : 'display:none;'; ?>">
                <h3>Standaard SMTP Gegevens</h3>
                <p><em>Standaard geconfigureerd voor de Brink Multimedia servers (Brabix).</em></p>
                <table class="form-table">
                    <tr>
                        <th scope="row">SMTP Host (Uitgaande server)</th>
                        <td><input type="text" name="mijn_smtp_options[host]" value="<?php echo esc_attr( $opties['host'] ?? 'shared01.brabix.nl' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Poort</th>
                        <td><input type="number" name="mijn_smtp_options[port]" value="<?php echo esc_attr( $opties['port'] ?? '587' ); ?>" class="small-text" /> <em>Meestal 587 of 465</em></td>
                    </tr>
                    <tr>
                        <th scope="row">Beveiliging</th>
                        <td>
                            <select name="mijn_smtp_options[secure]">
                                <option value="tls" <?php selected( $opties['secure'] ?? 'tls', 'tls' ); ?>>TLS</option>
                                <option value="starttls" <?php selected( $opties['secure'] ?? '', 'starttls' ); ?>>STARTTLS</option>
                                <option value="ssl" <?php selected( $opties['secure'] ?? '', 'ssl' ); ?>>SSL</option>
                                <option value="none" <?php selected( $opties['secure'] ?? '', 'none' ); ?>>Geen (Niet aanbevolen)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Authenticatie</th>
                        <td>
                            <label>
                                <input type="checkbox" name="mijn_smtp_options[auth]" value="yes" <?php checked( !isset($opties['auth']) || $opties['auth'] === 'yes' ); ?> />
                                AAN (Aanbevolen) - <em>Server vereist een gebruikersnaam en wachtwoord</em>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">SMTP Gebruikersnaam</th>
                        <td><input type="text" name="mijn_smtp_options[username]" value="<?php echo esc_attr( $opties['username'] ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">SMTP Wachtwoord</th>
                        <td><input type="password" name="mijn_smtp_options[password]" value="<?php echo esc_attr( $opties['password'] ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                </table>
            </div>

            <!-- MICROSOFT INSTELLINGEN -->
            <div id="sectie_microsoft" style="<?php echo $methode === 'microsoft' ? 'display:block;' : 'display:none;'; ?>">
                <h3>Microsoft Outlook (OAuth 2.0) API Gegevens</h3>
                
                <details style="background: #f0f6fc; border: 1px solid #c3c4c7; padding: 10px 15px; margin-bottom: 20px; border-radius: 4px;">
                    <summary style="font-weight: bold; cursor: pointer; color: #2271b1; outline: none;">📖 Handleiding: Hoe koppel je Microsoft Entra ID (Azure)? (Klik om uit te klappen)</summary>
                    <div style="margin-top: 15px; font-size: 13px; line-height: 1.5;">
                        <ol style="margin-left: 20px; list-style-type: decimal;">
                            <li>Ga naar de <a href="https://entra.microsoft.com/" target="_blank">Microsoft Entra admin center</a> (voorheen Azure AD) en log in als beheerder.</li>
                            <li>Ga in het linkermenu naar <strong>Toepassingen (Applications) > App-registraties</strong> en klik op <strong>Nieuwe registratie</strong>.</li>
                            <li>Geef de app een naam (bijv. "Website SMTP").</li>
                            <li>Bij <strong>Omleidings-URI (Redirect URI)</strong> kies je <strong>Web</strong> in de dropdown en plak je exact deze link:
                                <br><code style="user-select: all; display: inline-block; padding: 5px; background: #fff; border: 1px solid #c3c4c7; margin: 5px 0;"><?php echo admin_url( 'options-general.php?page=mijn-smtp-instellingen' ); ?></code>
                            </li>
                            <li>Klik onderaan op <strong>Registreren</strong>.</li>
                            <li>Kopieer op de overzichtspagina de <strong>Toepassings-id (client)</strong> en <strong>Map-id (tenant)</strong> naar de eerste twee velden hieronder in WordPress.</li>
                            <li>Ga in het linkermenu van je nieuwe app naar <strong>API-machtigingen (API permissions)</strong>.
                                <ul style="list-style-type: disc; margin-left: 20px; margin-top: 5px; margin-bottom: 5px;">
                                    <li>Klik op <strong>Een machtiging toevoegen</strong> > <strong>Microsoft Graph</strong> > <strong>Gedelegeerde machtigingen</strong>.</li>
                                    <li>Zoek in de lijst naar <code>SMTP</code> en vink <strong>SMTP.Send</strong> aan.</li>
                                    <li>Zoek naar <code>offline_access</code> en vink deze ook aan.</li>
                                    <li>Klik op <strong>Machtigingen toevoegen</strong> (onderaan).</li>
                                </ul>
                            </li>
                            <li><strong>CRUCIAAL:</strong> Klik op de knop "Beheerderstoestemming verlenen voor [Organisatie]". Deze staat net boven de lijst met rechten.</li>
                            <li>Ga in het linkermenu naar <strong>Certificaten & geheimen</strong> en klik op <strong>Nieuw clientgeheim (New client secret)</strong>.
                                <ul style="list-style-type: disc; margin-left: 20px; margin-top: 5px;">
                                    <li>Geef het een omschrijving en kies een geldigheidsduur.</li>
                                    <li>Kopieer direct de <strong>Waarde (Value)</strong>. Vul deze hieronder in.</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                </details>
                
                <?php if ( isset( $_GET['oauth'] ) && $_GET['oauth'] === 'success' ) : ?>
                    <div class="notice notice-success inline" style="margin-bottom:15px;"><p>✅ Succesvol gekoppeld met Microsoft!</p></div>
                <?php elseif ( isset( $_GET['oauth'] ) && $_GET['oauth'] === 'failed' ) : ?>
                    <div class="notice notice-error inline" style="margin-bottom:15px;"><p>❌ Koppelen met Microsoft mislukt. Controleer of je gegevens kloppen en of de Redirect URI exact overeenkomt in Azure.</p></div>
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Application (client) ID</th>
                        <td><input type="text" name="mijn_smtp_options[ms_client_id]" value="<?php echo esc_attr( $opties['ms_client_id'] ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Directory (tenant) ID</th>
                        <td><input type="text" name="mijn_smtp_options[ms_tenant_id]" value="<?php echo esc_attr( $opties['ms_tenant_id'] ?? '' ); ?>" class="regular-text" /> <br><small><em>(Laat leeg of vul 'common' in als je de Tenant ID niet weet)</em></small></td>
                    </tr>
                    <tr>
                        <th scope="row">Client Secret (Waarde)</th>
                        <td><input type="password" name="mijn_smtp_options[ms_client_secret]" value="<?php echo esc_attr( $opties['ms_client_secret'] ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Vervaldatum Secret (Optioneel)</th>
                        <td>
                            <input type="date" name="mijn_smtp_options[ms_secret_expiry]" value="<?php echo esc_attr( $opties['ms_secret_expiry'] ?? '' ); ?>" />
                            <br><small>Vul in wanneer de secret verloopt, dan waarschuwen we je 30 dagen van tevoren.</small>
                        </td>
                    </tr>
                </table>
                
                <?php 
                if ( !empty($opties['ms_client_id']) && !empty($opties['ms_client_secret']) ) : 
                    if ( empty($opties['ms_refresh_token']) ) :
                        $tenant = !empty($opties['ms_tenant_id']) ? $opties['ms_tenant_id'] : 'common';
                        $redirect_uri = urlencode( admin_url( 'options-general.php?page=mijn-smtp-instellingen' ) );
                        $scope = urlencode( 'offline_access https://outlook.office.com/SMTP.Send' );
                        $auth_url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize?client_id={$opties['ms_client_id']}&response_type=code&redirect_uri={$redirect_uri}&response_mode=query&scope={$scope}&state=authorize_ms";
                        ?>
                        <div style="margin-top: 20px; padding: 15px; border-left: 4px solid #f56e28; background: #fff;">
                            <strong>Stap 2: Autoriseer de App</strong><br>
                            <p>Sla eerst je instellingen hieronder op als je dat nog niet gedaan hebt. Klik daarna op deze knop om Microsoft toestemming te geven.</p>
                            <a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary">Koppel met Microsoft</a>
                        </div>
                    <?php else: ?>
                        <div style="margin-top: 20px; padding: 15px; border-left: 4px solid #46b450; background: #fff;">
                            <span style="color: green; font-weight: bold; font-size: 16px;">✅ Succesvol verbonden met Microsoft</span>
                            <p>Je verstuurt nu veilig e-mails via het OAuth protocol. <a href="<?php echo admin_url( 'options-general.php?page=mijn-smtp-instellingen&disconnect_ms=1' ); ?>" style="color: #d63638;">Verbreek koppeling</a></p>
                        </div>
                    <?php endif; 
                else: ?>
                    <p style="color: #888;"><em>Vul eerst de ID's en Secret in en klik op "Instellingen Opslaan" om de koppel-knop te zien.</em></p>
                <?php endif; ?>
            </div>

            <?php submit_button( 'Instellingen Opslaan' ); ?>
        </form>

        <hr>

        <!-- TEST E-MAIL FORMULIER -->
        <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; margin-top: 30px;">
            <h3 style="margin-top: 0;">Test Je Instellingen</h3>
            <p>Vul een e-mailadres in om te controleren of de bovenstaande instellingen werken (sla eerst je instellingen op!).</p>
            <form method="post" action="">
                <input type="email" name="mijn_smtp_test_email" class="regular-text" placeholder="jouw@email.nl" required />
                <button type="submit" name="mijn_smtp_test_submit" class="button button-secondary">Verstuur Test E-mail</button>
            </form>
        </div>

        <!-- E-MAIL LOGBOEK -->
        <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; margin-top: 30px;">
            <h3 style="margin-top: 0;">E-mail Logboek (Laatste 7 dagen)</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Datum & Tijd</th>
                        <th>Ontvanger</th>
                        <th>Onderwerp</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $logs = get_option('mijn_smtp_email_logs', array());
                    if(empty($logs)): ?>
                        <tr><td colspan="4">Er zijn nog geen e-mails gelogd in de afgelopen 7 dagen.</td></tr>
                    <?php else: ?>
                        <?php foreach($logs as $log): ?>
                            <tr>
                                <td><?php echo wp_date('d-m-Y H:i', $log['time']); ?></td>
                                <td><?php echo esc_html($log['to']); ?></td>
                                <td><?php echo esc_html($log['subject']); ?></td>
                                <td>
                                    <?php if($log['status'] === 'success'): ?>
                                        <span style="color: #46b450; font-weight: bold;">✅ Verzonden</span>
                                    <?php else: ?>
                                        <span style="color: #d63638; font-weight: bold;">❌ Mislukt</span>
                                        <br><small style="color: #d63638;"><?php echo esc_html($log['error']); ?></small>
                                        
                                        <?php if ( ! empty( $log['server_log'] ) ) : ?>
                                            <details style="margin-top: 5px;">
                                                <summary style="cursor: pointer; font-size: 11px; color: #2271b1;">Bekijk Server Log</summary>
                                                <pre style="margin-top: 5px; white-space: pre-wrap; font-size: 10px; background: #f6f7f7; padding: 5px; max-height: 150px; overflow-y: auto; color: #d63638; border: 1px solid #ccc;"><?php echo esc_html( $log['server_log'] ); ?></pre>
                                            </details>
                                        <?php endif; ?>
                                        
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <script>
        document.getElementById('mijn_smtp_methode').addEventListener('change', function() {
            if(this.value === 'standaard') {
                document.getElementById('sectie_standaard').style.display = 'block';
                document.getElementById('sectie_microsoft').style.display = 'none';
            } else {
                document.getElementById('sectie_standaard').style.display = 'none';
                document.getElementById('sectie_microsoft').style.display = 'block';
            }
        });
    </script>
    <?php
}

/**
 * 5. Teller en Logboek voor het bijhouden van verzonden e-mails
 */
add_filter( 'wp_mail', 'mijn_smtp_verwerk_mail_log' );
function mijn_smtp_verwerk_mail_log( $args ) {
    $huidige_teller = (int) get_option( 'mijn_smtp_wekelijkse_teller', 0 );
    update_option( 'mijn_smtp_wekelijkse_teller', $huidige_teller + 1 );
    
    $logs = get_option('mijn_smtp_email_logs', array());
    $to = is_array($args['to']) ? implode(', ', $args['to']) : $args['to'];
    $new_log = array(
        'id'         => uniqid(),
        'time'       => current_time('timestamp'),
        'to'         => $to,
        'subject'    => $args['subject'],
        'status'     => 'success',
        'error'      => '',
        'server_log' => ''
    );
    array_unshift($logs, $new_log);
    
    $logs = array_filter($logs, function($log) {
        return $log['time'] > (current_time('timestamp') - 7 * DAY_IN_SECONDS);
    });
    $logs = array_slice($logs, 0, 50);
    
    update_option('mijn_smtp_email_logs', $logs);
    set_transient( 'mijn_smtp_last_log_id', $new_log['id'], 60 );
    
    return $args;
}

/**
 * 5b. Log markeren als mislukt bij een error (Slaat nu OOK de server log op)
 */
add_action( 'wp_mail_failed', 'mijn_smtp_markeer_log_mislukt', 10, 1 );
function mijn_smtp_markeer_log_mislukt( $error ) {
    global $brink_smtp_global_debug;
    
    $last_id = get_transient( 'mijn_smtp_last_log_id' );
    if ( $last_id ) {
        $logs = get_option( 'mijn_smtp_email_logs', array() );
        foreach ( $logs as &$log ) {
            if ( $log['id'] === $last_id ) {
                $log['status']     = 'failed';
                $log['error']      = $error->get_error_message();
                $log['server_log'] = $brink_smtp_global_debug; // Sla de live server log op in database
                break;
            }
        }
        update_option( 'mijn_smtp_email_logs', $logs );
    }
}

/**
 * 6. Automatische taak (Cronjob) inplannen voor maandagen
 */
add_action( 'admin_init', 'mijn_smtp_plan_wekelijks_rapport' );
function mijn_smtp_plan_wekelijks_rapport() {
    if ( ! wp_next_scheduled( 'mijn_smtp_wekelijks_rapport_event' ) ) {
        $volgende_maandag = strtotime( 'next monday 08:00:00' );
        wp_schedule_event( $volgende_maandag, 'weekly', 'mijn_smtp_wekelijks_rapport_event' );
    }
}

/**
 * 7. De e-mail die daadwerkelijk verzonden wordt op maandag
 */
add_action( 'mijn_smtp_wekelijks_rapport_event', 'mijn_smtp_verstuur_wekelijks_rapport' );
function mijn_smtp_verstuur_wekelijks_rapport() {
    $aantal      = (int) get_option( 'mijn_smtp_wekelijkse_teller', 0 );
    $beheerder   = get_option( 'admin_email' );
    $sitenaam    = get_bloginfo( 'name' );
    
    $onderwerp = "Wekelijks SMTP Rapport - " . $sitenaam;
    $bericht  = "Hallo,\n\nDit is je wekelijkse rapportage voor '$sitenaam'.\nAantal verzonden e-mails: $aantal\n\nGroet,\nBrink SMTP";
    
    wp_mail( $beheerder, $onderwerp, $bericht );
    update_option( 'mijn_smtp_wekelijkse_teller', 0 );
}

register_deactivation_hook( __FILE__, 'mijn_smtp_deactiveer_cron' );
function mijn_smtp_deactiveer_cron() {
    wp_clear_scheduled_hook( 'mijn_smtp_wekelijks_rapport_event' );
}

/**
 * 8. DE MOTOR: Koppel de instellingen aan de e-mail functie
 */

function mijn_smtp_get_ms_access_token() {
    $opties = get_option( 'mijn_smtp_options', array() );
    if ( empty( $opties['ms_refresh_token'] ) || empty( $opties['ms_client_id'] ) || empty( $opties['ms_client_secret'] ) ) {
        return false;
    }

    $tenant = !empty($opties['ms_tenant_id']) ? $opties['ms_tenant_id'] : 'common';
    $url = "https://login.microsoftonline.com/" . $tenant . "/oauth2/v2.0/token";
    
    $body = array(
        'client_id'     => $opties['ms_client_id'],
        'client_secret' => $opties['ms_client_secret'],
        'refresh_token' => $opties['ms_refresh_token'],
        'grant_type'    => 'refresh_token',
    );

    $response = wp_remote_post( $url, array( 'body' => $body ) );
    
    if ( ! is_wp_error( $response ) ) {
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( isset($data['error']) ) {
            set_transient( 'mijn_smtp_ms_token_error', $data['error_description'], 60 );
            return false;
        }

        if ( isset( $data['access_token'] ) ) {
            if ( isset( $data['refresh_token'] ) && $data['refresh_token'] !== $opties['ms_refresh_token'] ) {
                $opties['ms_refresh_token'] = $data['refresh_token'];
                update_option( 'mijn_smtp_options', $opties );
            }
            return $data['access_token'];
        }
    }
    return false;
}

add_action( 'phpmailer_init', 'mijn_smtp_verwerk_verzending', 999 );
function mijn_smtp_verwerk_verzending( $phpmailer ) {
    global $brink_smtp_global_debug;
    $brink_smtp_global_debug = ''; // Reset bij elke nieuwe mail
    
    // Vang ALTIJD de server logs op en bewaar ze in de variabele
    $phpmailer->SMTPDebug = 3; 
    $phpmailer->Debugoutput = function($str, $level) {
        global $brink_smtp_global_debug;
        $brink_smtp_global_debug .= $str . "\n";
    };

    $opties = get_option( 'mijn_smtp_options', array() );
    $methode = isset( $opties['methode'] ) ? $opties['methode'] : 'standaard';

    // METHODE 1: STANDAARD SMTP (Brabix)
    if ( $methode === 'standaard' ) {
        $phpmailer->isSMTP();
        $phpmailer->Host = !empty( $opties['host'] ) ? $opties['host'] : 'shared01.brabix.nl';
        $phpmailer->Port = !empty( $opties['port'] ) ? $opties['port'] : 587;
        
        $secure = !empty( $opties['secure'] ) ? $opties['secure'] : 'tls';
        if ( $secure === 'none' ) {
            $phpmailer->SMTPAutoTLS = false;
            $phpmailer->SMTPSecure  = '';
        } elseif ( $secure === 'starttls' ) {
            $phpmailer->SMTPSecure = 'tls'; 
        } else {
            $phpmailer->SMTPSecure  = $secure;
        }

        $auth = isset( $opties['auth'] ) ? $opties['auth'] : 'yes'; 
        if ( $auth === 'yes' ) {
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = isset( $opties['username'] ) ? $opties['username'] : '';
            $phpmailer->Password = isset( $opties['password'] ) ? wp_unslash( $opties['password'] ) : '';
        } else {
            $phpmailer->SMTPAuth = false;
        }
    } 
    // METHODE 2: MICROSOFT OAUTH
    elseif ( $methode === 'microsoft' && !empty($opties['ms_refresh_token']) ) {
        $phpmailer->isSMTP();
        $phpmailer->Host       = 'smtp.office365.com';
        $phpmailer->Port       = 587;
        $phpmailer->SMTPSecure = 'tls';
        $phpmailer->SMTPAuth   = true;
        $phpmailer->AuthType   = 'XOAUTH2';

        $from_email = !empty($opties['from_email']) ? $opties['from_email'] : get_option('admin_email');
        $access_token = mijn_smtp_get_ms_access_token();
        
        if ( $access_token ) {
            if ( ! class_exists( 'Brink_Microsoft_OAuth_Provider' ) ) {
                class Brink_Microsoft_OAuth_Provider implements \PHPMailer\PHPMailer\OAuthTokenProvider {
                    private $email;
                    private $accessToken;
                    public function __construct( $email, $accessToken ) {
                        $this->email = $email;
                        $this->accessToken = $accessToken;
                    }
                    public function getOauth64() {
                        return base64_encode( "user=" . $this->email . "\001auth=Bearer " . $this->accessToken . "\001\001" );
                    }
                }
            }
            $phpmailer->setOAuth( new Brink_Microsoft_OAuth_Provider( $from_email, $access_token ) );
        }
    }
}