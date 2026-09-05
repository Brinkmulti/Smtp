<?php
/**
 * Plugin Name: Brink Multimedia SMTP
 * Plugin URI: https://www.brink-multimedia.nl
 * Description: Brink Multimedia SMTP Sender voor WordPress inclusief Microsoft OAuth en logging.
 * Version: 2.4
 * Author: Brink Multimedia
 * Author URI: https://www.brink-multimedia.nl
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Update URI: false
 */

// Voorkom directe toegang
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BRINK_SMTP_VERSION', '2.4' );

// Globale variabele om server communicatie op te vangen (alleen gevuld als debug-modus aan staat)
global $brink_smtp_global_debug;
$brink_smtp_global_debug = '';

/**
 * FEATURE: GitHub Auto-Updater (Het Radartje)
 * v2.4: resultaat wordt nu 12 uur gecachet, zodat we niet bij elke transient-refresh
 * een live call naar de GitHub API doen (die zonder token maar 60 verzoeken/uur per IP toestaat).
 */
add_filter( 'pre_set_site_transient_update_plugins', 'brink_smtp_github_check_update' );
function brink_smtp_github_check_update( $transient ) {
    if ( empty( $transient->checked ) ) {
        return $transient;
    }

    $plugin_slug = plugin_basename( __FILE__ );
    $release = get_transient( 'brink_smtp_github_release_cache' );

    if ( false === $release ) {
        $response = wp_remote_get( 'https://api.github.com/repos/Brinkmulti/Smtp/releases/latest', array(
            'headers' => array( 'Accept' => 'application/vnd.github.v3+json' ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            // Sla een "leeg" resultaat kort op, zodat we bij een storing niet blijven bonken op de API
            set_transient( 'brink_smtp_github_release_cache', array(), 15 * MINUTE_IN_SECONDS );
            return $transient;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ) );
        $release = array(
            'tag_name'    => isset( $data->tag_name ) ? $data->tag_name : '',
            'zipball_url' => isset( $data->zipball_url ) ? $data->zipball_url : '',
            'html_url'    => isset( $data->html_url ) ? $data->html_url : '',
        );
        set_transient( 'brink_smtp_github_release_cache', $release, 12 * HOUR_IN_SECONDS );
    }

    if ( ! empty( $release['tag_name'] ) ) {
        $github_versie = ltrim( $release['tag_name'], 'v' );

        if ( version_compare( BRINK_SMTP_VERSION, $github_versie, '<' ) ) {
            $plugin_info = new stdClass();
            $plugin_info->slug = current( explode( '/', $plugin_slug ) );
            $plugin_info->plugin = $plugin_slug;
            $plugin_info->new_version = $github_versie;
            $plugin_info->package = $release['zipball_url'];
            $plugin_info->url = $release['html_url'];

            $transient->response[ $plugin_slug ] = $plugin_info;
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
    if ( ! empty( $opties['force_from_email'] ) && ! empty( $opties['from_email'] ) ) {
        return $opties['from_email'];
    }
    return $email;
}

add_filter( 'wp_mail_from_name', 'mijn_smtp_force_from_name', 999 );
function mijn_smtp_force_from_name( $name ) {
    $opties = get_option( 'mijn_smtp_options', array() );
    if ( ! empty( $opties['force_from_name'] ) && ! empty( $opties['from_name'] ) ) {
        return $opties['from_name'];
    }
    return $name;
}

/**
 * FEATURE 2: Dashboard Widget
 */
add_action( 'wp_dashboard_setup', 'mijn_smtp_dashboard_widget_setup' );
function mijn_smtp_dashboard_widget_setup() {
    wp_add_dashboard_widget( 'mijn_smtp_dashboard_widget', 'Brink SMTP Statistieken', 'mijn_smtp_dashboard_widget_render' );
}
function mijn_smtp_dashboard_widget_render() {
    $huidige_teller = (int) get_option( 'mijn_smtp_wekelijkse_teller', 0 );
    echo '<p>Er zijn deze week <strong>' . esc_html( $huidige_teller ) . '</strong> e-mails succesvol verzonden via jouw SMTP instellingen.</p>';
    echo "<a href='" . esc_url( admin_url( 'options-general.php?page=mijn-smtp-instellingen' ) ) . "' class='button button-primary'>Bekijk Logboek & Instellingen</a>";
}

/**
 * FEATURE 3: Microsoft Token Expiry Warning
 */
add_action( 'admin_notices', 'mijn_smtp_check_expiry_notice' );
function mijn_smtp_check_expiry_notice() {
    $opties = get_option( 'mijn_smtp_options', array() );
    if ( ! empty( $opties['methode'] ) && $opties['methode'] === 'microsoft' && ! empty( $opties['ms_secret_expiry'] ) ) {
        $expiry = strtotime( $opties['ms_secret_expiry'] );
        $now = current_time( 'timestamp' );
        $diff = $expiry - $now;
        $days = floor( $diff / DAY_IN_SECONDS );

        if ( $days <= 30 && $days >= 0 ) {
            echo "<div class='notice notice-warning'><p>⚠️ <strong>Brink Multimedia SMTP:</strong> Let op! Je Microsoft Client Secret verloopt over <strong>" . esc_html( $days ) . " dagen</strong>. Maak op tijd een nieuwe aan in Azure (en werk de datum hier bij).</p></div>";
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
 * v2.4: sanitize_callback toegevoegd. Zonder deze werd elke $_POST-waarde ongefilterd
 * opgeslagen, inclusief het per ongeluk overschrijven van het Microsoft refresh-token
 * (dat niet in dit formulier staat) met een lege waarde.
 */
add_action( 'admin_init', 'mijn_smtp_registreer_instellingen' );
function mijn_smtp_registreer_instellingen() {
    register_setting( 'mijn_smtp_settings_group', 'mijn_smtp_options', array(
        'sanitize_callback' => 'mijn_smtp_sanitize_opties',
    ) );
}

function mijn_smtp_sanitize_opties( $input ) {
    $input = is_array( $input ) ? wp_unslash( $input ) : array();
    $bestaand = get_option( 'mijn_smtp_options', array() );
    $output = array();

    $output['from_email'] = isset( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : '';
    $output['from_name']  = isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '';
    $output['force_from_email'] = ! empty( $input['force_from_email'] ) ? 1 : 0;
    $output['force_from_name']  = ! empty( $input['force_from_name'] ) ? 1 : 0;

    $toegestane_methodes = array( 'standaard', 'microsoft' );
    $output['methode'] = ( isset( $input['methode'] ) && in_array( $input['methode'], $toegestane_methodes, true ) ) ? $input['methode'] : 'standaard';

    $output['host'] = isset( $input['host'] ) && $input['host'] !== '' ? sanitize_text_field( $input['host'] ) : 'shared01.brabix.nl';
    $output['port'] = isset( $input['port'] ) ? absint( $input['port'] ) : 587;

    $toegestane_secure = array( 'tls', 'starttls', 'ssl', 'none' );
    $output['secure'] = ( isset( $input['secure'] ) && in_array( $input['secure'], $toegestane_secure, true ) ) ? $input['secure'] : 'tls';

    $output['auth'] = ( isset( $input['auth'] ) && $input['auth'] === 'yes' ) ? 'yes' : 'no';
    $output['username'] = isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '';

    // Wachtwoord: veld wordt leeg getoond. Alleen overschrijven als er iets is ingevuld.
    $output['password'] = ( isset( $input['password'] ) && $input['password'] !== '' )
        ? $input['password']
        : ( isset( $bestaand['password'] ) ? $bestaand['password'] : '' );

    $output['ms_client_id'] = isset( $input['ms_client_id'] ) ? sanitize_text_field( $input['ms_client_id'] ) : '';
    $output['ms_tenant_id'] = isset( $input['ms_tenant_id'] ) ? sanitize_text_field( $input['ms_tenant_id'] ) : '';

    // Client secret: zelfde principe als wachtwoord hierboven.
    $output['ms_client_secret'] = ( isset( $input['ms_client_secret'] ) && $input['ms_client_secret'] !== '' )
        ? $input['ms_client_secret']
        : ( isset( $bestaand['ms_client_secret'] ) ? $bestaand['ms_client_secret'] : '' );

    $output['ms_secret_expiry'] = isset( $input['ms_secret_expiry'] ) ? sanitize_text_field( $input['ms_secret_expiry'] ) : '';

    // Refresh token wordt uitsluitend door de OAuth-flow beheerd, nooit via dit formulier.
    $output['ms_refresh_token'] = isset( $bestaand['ms_refresh_token'] ) ? $bestaand['ms_refresh_token'] : '';

    $output['debug_mode'] = ! empty( $input['debug_mode'] ) ? 1 : 0;

    return $output;
}

/**
 * 2b. Callback afhandeling voor Microsoft OAuth
 * v2.4:
 *  - current_user_can() check toegevoegd aan het BEGIN van de functie (ontbrak volledig).
 *  - 'state' is nu een willekeurige, eenmalige waarde (was een vaste string) om OAuth-CSRF te voorkomen.
 *  - Loskoppelen vereist nu een nonce (was een kale GET-request zonder enige bescherming).
 */
add_action( 'admin_init', 'mijn_smtp_handle_oauth_callback' );
function mijn_smtp_handle_oauth_callback() {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'mijn-smtp-instellingen' ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Luister naar de terugkeer van Microsoft
    if ( isset( $_GET['code'] ) && isset( $_GET['state'] ) ) {
        $state_key = 'mijn_smtp_ms_oauth_state_' . get_current_user_id();
        $verwachte_state = get_transient( $state_key );
        delete_transient( $state_key ); // eenmalig bruikbaar, ongeacht de uitkomst hieronder

        if ( ! $verwachte_state || ! hash_equals( $verwachte_state, wp_unslash( $_GET['state'] ) ) ) {
            wp_die(
                'Deze Microsoft-koppelaanvraag is ongeldig of verlopen. Ga terug naar de instellingenpagina en probeer opnieuw te koppelen.',
                'Beveiligingscontrole mislukt',
                array( 'response' => 403 )
            );
        }

        $opties = get_option( 'mijn_smtp_options', array() );
        $tenant = ! empty( $opties['ms_tenant_id'] ) ? $opties['ms_tenant_id'] : 'common';

        $url = 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/token';
        $redirect_uri = admin_url( 'options-general.php?page=mijn-smtp-instellingen' );

        $body = array(
            'client_id'     => isset( $opties['ms_client_id'] ) ? $opties['ms_client_id'] : '',
            'client_secret' => isset( $opties['ms_client_secret'] ) ? $opties['ms_client_secret'] : '',
            'code'          => sanitize_text_field( wp_unslash( $_GET['code'] ) ),
            'redirect_uri'  => $redirect_uri,
            'grant_type'    => 'authorization_code',
        );

        $response = wp_remote_post( $url, array( 'body' => $body, 'timeout' => 15 ) );
        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $data['refresh_token'] ) ) {
                $opties['ms_refresh_token'] = $data['refresh_token'];
                update_option( 'mijn_smtp_options', $opties, false );

                wp_safe_redirect( admin_url( 'options-general.php?page=mijn-smtp-instellingen&oauth=success' ) );
                exit;
            }
        }
        wp_safe_redirect( admin_url( 'options-general.php?page=mijn-smtp-instellingen&oauth=failed' ) );
        exit;
    }

    // Luister naar het loskoppelen
    if ( isset( $_GET['disconnect_ms'] ) ) {
        check_admin_referer( 'mijn_smtp_disconnect_ms' );

        $opties = get_option( 'mijn_smtp_options', array() );
        unset( $opties['ms_refresh_token'] );
        update_option( 'mijn_smtp_options', $opties, false );
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
        check_admin_referer( 'mijn_smtp_test_mail_action', 'mijn_smtp_test_nonce' );

        $to = sanitize_email( wp_unslash( $_POST['mijn_smtp_test_email'] ) );

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

            echo '<p style="color: #d63638; font-weight:bold; margin-top: 10px;">De server heeft de verbinding geweigerd. Bekijk het Logboek hieronder voor de specifieke code (zet evt. Debug-logging aan voor meer detail).</p>';
            echo '</div>';

            delete_transient( 'mijn_smtp_ms_token_error' );
        }
    }
    // --- EINDE: TEST E-MAIL AFHANDELING ---

    // --- BEGIN: LOGBOEK LEGEN ---
    if ( isset( $_POST['mijn_smtp_clear_log'] ) && current_user_can( 'manage_options' ) ) {
        check_admin_referer( 'mijn_smtp_clear_log_action', 'mijn_smtp_clear_log_nonce' );
        update_option( 'mijn_smtp_email_logs', array(), false );
        update_option( 'mijn_smtp_wekelijkse_teller', 0, false );
        echo '<div class="notice notice-success is-dismissible"><p>✅ Logboek is geleegd.</p></div>';
    }
    // --- EINDE: LOGBOEK LEGEN ---

    // v2.4: forceer autoload=false ná een settings-save. De Settings API (options.php)
    // slaat de optie zelf op met de standaard autoload-instelling; wij zetten hem hier
    // expliciet op 'nee' zodat deze data niet op elke pagina van de hele website wordt
    // ingeladen, terwijl hij alleen hier en bij het versturen van mail nodig is.
    if ( isset( $_GET['settings-updated'] ) && current_user_can( 'manage_options' ) ) {
        $huidige_opties = get_option( 'mijn_smtp_options', array() );
        update_option( 'mijn_smtp_options', $huidige_opties, false );
    }

    $opties = get_option( 'mijn_smtp_options', array() );
    $methode  = isset( $opties['methode'] ) ? $opties['methode'] : 'standaard';
    $from     = isset( $opties['from_email'] ) ? $opties['from_email'] : get_option( 'admin_email' );
    $fromnaam = isset( $opties['from_name'] ) ? $opties['from_name'] : get_bloginfo( 'name' );

    $huidige_teller = (int) get_option( 'mijn_smtp_wekelijkse_teller', 0 );
    ?>
    <div class="wrap">
        <h2>Brink Multimedia SMTP Instellingen</h2>

        <div style="background: #fff; padding: 15px; border-left: 4px solid #2271b1; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px;">
            <h3 style="margin-top: 0;">📊 Wekelijkse Statistieken</h3>
            <p>Er zijn deze week <strong><?php echo esc_html( $huidige_teller ); ?></strong> e-mails verzonden via deze plugin. <br>
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
                            <input type="checkbox" name="mijn_smtp_options[force_from_email]" value="1" <?php checked( ! empty( $opties['force_from_email'] ) ); ?> />
                            Forceer dit e-mailadres voor <strong>alle</strong> plugins (Aanbevolen)
                        </label>
                        <br>
                        <label style="margin-top: 5px; display: inline-block;">
                            <input type="checkbox" name="mijn_smtp_options[force_from_name]" value="1" <?php checked( ! empty( $opties['force_from_name'] ) ); ?> />
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
                <tr>
                    <th scope="row">Debug-logging</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mijn_smtp_options[debug_mode]" value="1" <?php checked( ! empty( $opties['debug_mode'] ) ); ?> />
                            Sla de serverconversatie op bij een mislukte verzending (tijdelijk aanzetten om te troubleshooten)
                        </label>
                        <p class="description">Staat standaard uit. Regels die mogelijk inloggegevens bevatten worden automatisch verborgen, maar zet dit alleen aan zolang je een probleem onderzoekt.</p>
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
                                <input type="checkbox" name="mijn_smtp_options[auth]" value="yes" <?php checked( ! isset( $opties['auth'] ) || $opties['auth'] === 'yes' ); ?> />
                                AAN (Aanbevolen) - <em>Server vereist een gebruikersnaam en wachtwoord</em>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">SMTP Gebruikersnaam</th>
                        <td><input type="text" name="mijn_smtp_options[username]" value="<?php echo esc_attr( $opties['username'] ?? '' ); ?>" class="regular-text" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th scope="row">SMTP Wachtwoord</th>
                        <td>
                            <input type="password" name="mijn_smtp_options[password]" value="" class="regular-text" autocomplete="new-password"
                                placeholder="<?php echo ! empty( $opties['password'] ) ? '•••••••• (laat leeg om ongewijzigd te laten)' : 'Voer wachtwoord in'; ?>" />
                        </td>
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
                                <br><code style="user-select: all; display: inline-block; padding: 5px; background: #fff; border: 1px solid #c3c4c7; margin: 5px 0;"><?php echo esc_html( admin_url( 'options-general.php?page=mijn-smtp-instellingen' ) ); ?></code>
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
                        <td>
                            <input type="password" name="mijn_smtp_options[ms_client_secret]" value="" class="regular-text" autocomplete="new-password"
                                placeholder="<?php echo ! empty( $opties['ms_client_secret'] ) ? '•••••••• (laat leeg om ongewijzigd te laten)' : 'Voer client secret in'; ?>" />
                        </td>
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
                if ( ! empty( $opties['ms_client_id'] ) && ! empty( $opties['ms_client_secret'] ) ) :
                    if ( empty( $opties['ms_refresh_token'] ) ) :
                        $tenant = ! empty( $opties['ms_tenant_id'] ) ? $opties['ms_tenant_id'] : 'common';
                        $redirect_uri = rawurlencode( admin_url( 'options-general.php?page=mijn-smtp-instellingen' ) );
                        $scope = rawurlencode( 'offline_access https://outlook.office.com/SMTP.Send' );

                        // v2.4: willekeurige, eenmalige state i.p.v. een vaste string ('authorize_ms').
                        $state = wp_generate_password( 32, false );
                        set_transient( 'mijn_smtp_ms_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS );

                        $auth_url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize?client_id=" . rawurlencode( $opties['ms_client_id'] ) . "&response_type=code&redirect_uri={$redirect_uri}&response_mode=query&scope={$scope}&state=" . rawurlencode( $state );
                        ?>
                        <div style="margin-top: 20px; padding: 15px; border-left: 4px solid #f56e28; background: #fff;">
                            <strong>Stap 2: Autoriseer de App</strong><br>
                            <p>Sla eerst je instellingen hieronder op als je dat nog niet gedaan hebt. Klik daarna op deze knop om Microsoft toestemming te geven.</p>
                            <a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary">Koppel met Microsoft</a>
                        </div>
                    <?php else: ?>
                        <div style="margin-top: 20px; padding: 15px; border-left: 4px solid #46b450; background: #fff;">
                            <span style="color: green; font-weight: bold; font-size: 16px;">✅ Succesvol verbonden met Microsoft</span>
                            <p>Je verstuurt nu veilig e-mails via het OAuth protocol.
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=mijn-smtp-instellingen&disconnect_ms=1' ), 'mijn_smtp_disconnect_ms' ) ); ?>" style="color: #d63638;">Verbreek koppeling</a>
                            </p>
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
                <?php wp_nonce_field( 'mijn_smtp_test_mail_action', 'mijn_smtp_test_nonce' ); ?>
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
                    $logs = get_option( 'mijn_smtp_email_logs', array() );
                    if ( empty( $logs ) ): ?>
                        <tr><td colspan="4">Er zijn nog geen e-mails gelogd in de afgelopen 7 dagen.</td></tr>
                    <?php else: ?>
                        <?php foreach ( $logs as $log ): ?>
                            <tr>
                                <td><?php echo esc_html( wp_date( 'd-m-Y H:i', $log['time'] ) ); ?></td>
                                <td><?php echo esc_html( $log['to'] ); ?></td>
                                <td><?php echo esc_html( $log['subject'] ); ?></td>
                                <td>
                                    <?php if ( $log['status'] === 'success' ): ?>
                                        <span style="color: #46b450; font-weight: bold;">✅ Verzonden</span>
                                    <?php else: ?>
                                        <span style="color: #d63638; font-weight: bold;">❌ Mislukt</span>
                                        <br><small style="color: #d63638;"><?php echo esc_html( $log['error'] ); ?></small>

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
            <?php if ( ! empty( $logs ) ) : ?>
                <form method="post" action="" style="margin-top: 10px;" onsubmit="return confirm('Weet je zeker dat je het hele logboek wilt legen?');">
                    <?php wp_nonce_field( 'mijn_smtp_clear_log_action', 'mijn_smtp_clear_log_nonce' ); ?>
                    <button type="submit" name="mijn_smtp_clear_log" class="button">Logboek legen</button>
                </form>
            <?php endif; ?>
        </div>

    </div>

    <script>
        document.getElementById('mijn_smtp_methode').addEventListener('change', function() {
            if (this.value === 'standaard') {
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
 * v2.4: opgeslagen met autoload=false (3e argument), zodat deze data niet op elke
 * pagina van de hele website wordt meegeladen — alleen wanneer hij echt nodig is.
 */
add_filter( 'wp_mail', 'mijn_smtp_verwerk_mail_log' );
function mijn_smtp_verwerk_mail_log( $args ) {
    $huidige_teller = (int) get_option( 'mijn_smtp_wekelijkse_teller', 0 );
    update_option( 'mijn_smtp_wekelijkse_teller', $huidige_teller + 1, false );

    $logs = get_option( 'mijn_smtp_email_logs', array() );
    $to = is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : $args['to'];
    $new_log = array(
        'id'         => uniqid(),
        'time'       => current_time( 'timestamp' ),
        'to'         => $to,
        'subject'    => $args['subject'],
        'status'     => 'success',
        'error'      => '',
        'server_log' => '',
    );
    array_unshift( $logs, $new_log );

    $logs = array_filter( $logs, function( $log ) {
        return $log['time'] > ( current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS );
    } );
    $logs = array_slice( $logs, 0, 50 );

    update_option( 'mijn_smtp_email_logs', $logs, false );
    set_transient( 'mijn_smtp_last_log_id', $new_log['id'], 60 );

    return $args;
}

/**
 * 5b. Log markeren als mislukt bij een error (slaat de (gefilterde) server log op)
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
                $log['server_log'] = $brink_smtp_global_debug;
                break;
            }
        }
        unset( $log );
        update_option( 'mijn_smtp_email_logs', $logs, false );
    }
}

/**
 * 6. Automatische taak (Cronjob) inplannen voor maandagen
 * v2.4: verplaatst van 'admin_init' (draaide op elke admin-paginalaad) naar de
 * activatie-hook, zodat de check nog maar één keer plaatsvindt.
 */
register_activation_hook( __FILE__, 'mijn_smtp_activeer_plugin' );
function mijn_smtp_activeer_plugin() {
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
    $aantal    = (int) get_option( 'mijn_smtp_wekelijkse_teller', 0 );
    $beheerder = get_option( 'admin_email' );
    $sitenaam  = get_bloginfo( 'name' );

    $onderwerp = "Wekelijks SMTP Rapport - " . $sitenaam;
    $bericht   = "Hallo,\n\nDit is je wekelijkse rapportage voor '$sitenaam'.\nAantal verzonden e-mails: $aantal\n\nGroet,\nBrink SMTP";

    wp_mail( $beheerder, $onderwerp, $bericht );
    update_option( 'mijn_smtp_wekelijkse_teller', 0, false );
}

register_deactivation_hook( __FILE__, 'mijn_smtp_deactiveer_cron' );
function mijn_smtp_deactiveer_cron() {
    wp_clear_scheduled_hook( 'mijn_smtp_wekelijks_rapport_event' );
}

/**
 * FEATURE: opruimen bij verwijderen van de plugin (nieuw in v2.4)
 * Zonder dit blijven wachtwoorden, secrets en logs achter in de database
 * nadat iemand de plugin verwijdert.
 */
register_uninstall_hook( __FILE__, 'mijn_smtp_uninstall' );
function mijn_smtp_uninstall() {
    delete_option( 'mijn_smtp_options' );
    delete_option( 'mijn_smtp_email_logs' );
    delete_option( 'mijn_smtp_wekelijkse_teller' );
    delete_transient( 'mijn_smtp_last_log_id' );
    delete_transient( 'mijn_smtp_ms_token_error' );
    delete_transient( 'brink_smtp_github_release_cache' );
}

/**
 * 8. DE MOTOR: Koppel de instellingen aan de e-mail functie
 */

function mijn_smtp_get_ms_access_token() {
    $opties = get_option( 'mijn_smtp_options', array() );
    if ( empty( $opties['ms_refresh_token'] ) || empty( $opties['ms_client_id'] ) || empty( $opties['ms_client_secret'] ) ) {
        return false;
    }

    $tenant = ! empty( $opties['ms_tenant_id'] ) ? $opties['ms_tenant_id'] : 'common';
    $url = 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/token';

    $body = array(
        'client_id'     => $opties['ms_client_id'],
        'client_secret' => $opties['ms_client_secret'],
        'refresh_token' => $opties['ms_refresh_token'],
        'grant_type'    => 'refresh_token',
    );

    $response = wp_remote_post( $url, array( 'body' => $body, 'timeout' => 15 ) );

    if ( ! is_wp_error( $response ) ) {
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['error'] ) ) {
            set_transient( 'mijn_smtp_ms_token_error', $data['error_description'], 60 );
            return false;
        }

        if ( isset( $data['access_token'] ) ) {
            if ( isset( $data['refresh_token'] ) && $data['refresh_token'] !== $opties['ms_refresh_token'] ) {
                $opties['ms_refresh_token'] = $data['refresh_token'];
                update_option( 'mijn_smtp_options', $opties, false );
            }
            return $data['access_token'];
        }
    }
    return false;
}

/**
 * Verbergt regels uit de PHPMailer-debuguitvoer die vermoedelijk inloggegevens
 * of tokens bevatten (bijv. "AUTH LOGIN" gevolgd door base64-gecodeerde waarden,
 * of de "Bearer ..." string bij XOAUTH2). Dit is een extra vangnet bovenop het
 * feit dat debug-logging standaard uitstaat — geen garantie, wel verstandig.
 */
function mijn_smtp_redact_debug_regel( $regel ) {
    if ( preg_match( '/\b(AUTH|Authorization|Bearer|XOAUTH2)\b/i', $regel ) ) {
        return '[GEREDIGEERD - regel kan inloggegevens bevatten]';
    }
    if ( preg_match( '/^[A-Za-z0-9+\/=]{24,}$/', trim( $regel ) ) ) {
        return '[GEREDIGEERD - mogelijk gecodeerde inloggegevens]';
    }
    return $regel;
}

add_action( 'phpmailer_init', 'mijn_smtp_verwerk_verzending', 999 );
function mijn_smtp_verwerk_verzending( $phpmailer ) {
    global $brink_smtp_global_debug;
    $brink_smtp_global_debug = '';

    $opties = get_option( 'mijn_smtp_options', array() );

    // v2.4: debug-logging staat standaard uit en wordt alleen aangezet als de
    // beheerder dat expliciet in de instellingen heeft aangevinkt.
    if ( ! empty( $opties['debug_mode'] ) ) {
        $phpmailer->SMTPDebug = 2;
        $phpmailer->Debugoutput = function( $str, $level ) {
            global $brink_smtp_global_debug;
            $brink_smtp_global_debug .= mijn_smtp_redact_debug_regel( $str ) . "\n";
        };
    } else {
        $phpmailer->SMTPDebug = 0;
    }

    $methode = isset( $opties['methode'] ) ? $opties['methode'] : 'standaard';

    // METHODE 1: STANDAARD SMTP (Brabix)
    if ( $methode === 'standaard' ) {
        $phpmailer->isSMTP();
        $phpmailer->Host = ! empty( $opties['host'] ) ? $opties['host'] : 'shared01.brabix.nl';
        $phpmailer->Port = ! empty( $opties['port'] ) ? $opties['port'] : 587;

        $secure = ! empty( $opties['secure'] ) ? $opties['secure'] : 'tls';
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
            $phpmailer->Password = isset( $opties['password'] ) ? $opties['password'] : '';
        } else {
            $phpmailer->SMTPAuth = false;
        }
    }
    // METHODE 2: MICROSOFT OAUTH
    elseif ( $methode === 'microsoft' && ! empty( $opties['ms_refresh_token'] ) ) {
        $phpmailer->isSMTP();
        $phpmailer->Host       = 'smtp.office365.com';
        $phpmailer->Port       = 587;
        $phpmailer->SMTPSecure = 'tls';
        $phpmailer->SMTPAuth   = true;
        $phpmailer->AuthType   = 'XOAUTH2';

        $from_email = ! empty( $opties['from_email'] ) ? $opties['from_email'] : get_option( 'admin_email' );
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
