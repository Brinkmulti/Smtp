<?php
/**
 * Plugin Name: Brink Multimedia SMTP
 * Plugin URI: https://www.brink-multimedia.nl
 * Description: Brink Multimedia SMTP Sender voor WordPress inclusief Microsoft-, Google- en SendGrid-koppeling en logging.
 * Version: 4.0
 * Author: Brink Multimedia
 * Author URI: https://www.brink-multimedia.nl
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Update URI: false
 */

// Voorkom directe toegang
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BRINK_SMTP_VERSION', '4.0' );

// Globale variabele om server communicatie op te vangen (alleen gevuld als debug-modus aan staat)
global $brink_smtp_global_debug;
$brink_smtp_global_debug = '';

/**
 * Registreer een 'weekly' en 'monthly' cron-interval.
 * v3.0: bugfix - WordPress kent standaard GEEN 'weekly' interval. De cronjob voor het
 * rapport werd hierdoor in de praktijk nooit correct ingepland tenzij een andere
 * plugin toevallig ditzelfde interval al registreerde.
 */
add_filter( 'cron_schedules', 'mijn_smtp_cron_intervallen' );
function mijn_smtp_cron_intervallen( $schedules ) {
    if ( ! isset( $schedules['weekly'] ) ) {
        $schedules['weekly'] = array( 'interval' => 7 * DAY_IN_SECONDS, 'display' => 'Wekelijks' );
    }
    if ( ! isset( $schedules['monthly'] ) ) {
        $schedules['monthly'] = array( 'interval' => 30 * DAY_IN_SECONDS, 'display' => 'Maandelijks (elke 30 dagen)' );
    }
    return $schedules;
}

/**
 * FEATURE: GitHub Auto-Updater (Het Radartje) — resultaat wordt 12 uur gecachet.
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
 * FEATURE 1: Forceer Afzender E-mail en Naam (+ per-afzender overrides, v3.0 - suggestie #37)
 * De 'wp_mail_from' filter draait vóór 'wp_mail_from_name' binnen dezelfde wp_mail()-aanroep,
 * dus we gebruiken een globale variabele om de gematchte override-regel door te geven.
 */
add_filter( 'wp_mail_from', 'mijn_smtp_force_from_email', 999 );
function mijn_smtp_force_from_email( $email ) {
    global $mijn_smtp_matched_override;
    $mijn_smtp_matched_override = null;

    $opties = get_option( 'mijn_smtp_options', array() );

    if ( ! empty( $opties['from_overrides'] ) && is_array( $opties['from_overrides'] ) ) {
        foreach ( $opties['from_overrides'] as $regel ) {
            if ( ! empty( $regel['match_email'] ) && ! empty( $regel['from_email'] ) && strcasecmp( $regel['match_email'], $email ) === 0 ) {
                $mijn_smtp_matched_override = $regel;
                return $regel['from_email'];
            }
        }
    }

    if ( ! empty( $opties['force_from_email'] ) && ! empty( $opties['from_email'] ) ) {
        return $opties['from_email'];
    }
    return $email;
}

add_filter( 'wp_mail_from_name', 'mijn_smtp_force_from_name', 999 );
function mijn_smtp_force_from_name( $name ) {
    global $mijn_smtp_matched_override;

    if ( ! empty( $mijn_smtp_matched_override['from_name'] ) ) {
        return $mijn_smtp_matched_override['from_name'];
    }

    $opties = get_option( 'mijn_smtp_options', array() );
    if ( ! empty( $opties['force_from_name'] ) && ! empty( $opties['from_name'] ) ) {
        return $opties['from_name'];
    }
    return $name;
}

/**
 * Verzamelt verzonden/mislukte aantallen per dag uit het logboek (voor grafiek + sparkline).
 */
function mijn_smtp_verzamel_dagelijkse_stats( $aantal_dagen = 7 ) {
    $logs = get_option( 'mijn_smtp_email_logs', array() );
    $nu = current_time( 'timestamp' );
    $dagen = array();

    for ( $i = $aantal_dagen - 1; $i >= 0; $i-- ) {
        $dag_start = strtotime( 'today', $nu ) - ( $i * DAY_IN_SECONDS );
        $dag_key = wp_date( 'Y-m-d', $dag_start );
        $dagen[ $dag_key ] = array( 'label' => wp_date( 'd-m', $dag_start ), 'verzonden' => 0, 'mislukt' => 0 );
    }

    foreach ( $logs as $log ) {
        $dag_key = wp_date( 'Y-m-d', $log['time'] );
        if ( isset( $dagen[ $dag_key ] ) ) {
            if ( $log['status'] === 'success' ) {
                $dagen[ $dag_key ]['verzonden']++;
            } else {
                $dagen[ $dag_key ]['mislukt']++;
            }
        }
    }

    return array_values( $dagen );
}

/**
 * Tekent een lichte, afhankelijkheidsvrije SVG-staafdiagram (verzonden vs. mislukt per dag).
 * v3.0 - suggestie #16. Bewust geen Chart.js: dat zou een externe library aan een
 * single-file plugin toevoegen voor één klein grafiekje op één instellingenpagina.
 */
function mijn_smtp_render_dag_grafiek( $dagen ) {
    $max = 1;
    foreach ( $dagen as $dag ) {
        $max = max( $max, $dag['verzonden'], $dag['mislukt'] );
    }
    $breedte = 320;
    $hoogte = 110;
    $onder_marge = 20;
    $aantal = max( count( $dagen ), 1 );
    $groep_breedte = $breedte / $aantal;
    $bar_breedte = max( ( $groep_breedte - 8 ) / 2, 2 );

    $svg = '<svg viewBox="0 0 ' . $breedte . ' ' . ( $hoogte + $onder_marge ) . '" xmlns="http://www.w3.org/2000/svg" style="width:100%;max-width:420px;height:auto;">';

    foreach ( $dagen as $i => $dag ) {
        $x = $i * $groep_breedte + 4;
        $h_verzonden = ( $dag['verzonden'] / $max ) * $hoogte;
        $h_mislukt   = ( $dag['mislukt'] / $max ) * $hoogte;

        $svg .= sprintf(
            '<rect x="%1$.1f" y="%2$.1f" width="%3$.1f" height="%4$.1f" fill="var(--brink-success, #46b450)" rx="2"></rect>',
            $x, $hoogte - $h_verzonden, $bar_breedte, $h_verzonden
        );
        $svg .= sprintf(
            '<rect x="%1$.1f" y="%2$.1f" width="%3$.1f" height="%4$.1f" fill="var(--brink-error, #d63638)" rx="2"></rect>',
            $x + $bar_breedte + 2, $hoogte - $h_mislukt, $bar_breedte, $h_mislukt
        );
        $svg .= sprintf(
            '<text x="%1$.1f" y="%2$d" font-size="9" text-anchor="middle" fill="var(--brink-text-muted, #646970)">%3$s</text>',
            $x + $groep_breedte / 2, $hoogte + 14, esc_html( $dag['label'] )
        );
    }

    $svg .= '</svg>';
    return $svg;
}

/**
 * Tekent een minimale SVG-sparkline voor het dashboard-widget (suggestie #35).
 */
function mijn_smtp_render_sparkline( $dagen ) {
    $totalen = array_map( function( $dag ) { return $dag['verzonden'] + $dag['mislukt']; }, $dagen );
    $max = max( 1, max( $totalen ) );
    $breedte = 140;
    $hoogte = 30;
    $aantal = max( count( $totalen ) - 1, 1 );
    $punten = array();

    foreach ( $totalen as $i => $waarde ) {
        $x = ( $i / $aantal ) * $breedte;
        $y = $hoogte - ( ( $waarde / $max ) * ( $hoogte - 4 ) ) - 2;
        $punten[] = round( $x, 1 ) . ',' . round( $y, 1 );
    }

    $svg  = '<svg viewBox="0 0 ' . $breedte . ' ' . $hoogte . '" xmlns="http://www.w3.org/2000/svg" style="width:100%;max-width:160px;height:30px;display:block;margin-top:6px;">';
    $svg .= '<polyline points="' . esc_attr( implode( ' ', $punten ) ) . '" fill="none" stroke="var(--brink-accent, #2271b1)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>';
    $svg .= '</svg>';

    return $svg;
}

/**
 * FEATURE 2: Dashboard Widget (met sparkline, v3.0)
 */
add_action( 'wp_dashboard_setup', 'mijn_smtp_dashboard_widget_setup' );
function mijn_smtp_dashboard_widget_setup() {
    wp_add_dashboard_widget( 'mijn_smtp_dashboard_widget', 'Brink SMTP Statistieken', 'mijn_smtp_dashboard_widget_render' );
}
function mijn_smtp_dashboard_widget_render() {
    $huidige_teller = (int) get_option( 'mijn_smtp_wekelijkse_teller', 0 );
    echo '<p>Er zijn deze week <strong>' . esc_html( $huidige_teller ) . '</strong> e-mails succesvol verzonden via jouw SMTP instellingen.</p>';
    echo mijn_smtp_render_sparkline( mijn_smtp_verzamel_dagelijkse_stats( 7 ) );
    echo "<a href='" . esc_url( admin_url( 'options-general.php?page=mijn-smtp-instellingen' ) ) . "' class='button button-primary' style='margin-top:8px;'>Bekijk Logboek & Instellingen</a>";
}

/**
 * FEATURE (v3.2 - suggestie #16): snelkoppeling in de WordPress-adminbalk om direct
 * een testmail naar je eigen e-mailadres te sturen, zonder eerst naar de
 * instellingenpagina te hoeven navigeren.
 */
add_action( 'admin_bar_menu', 'mijn_smtp_admin_bar_snelkoppeling', 100 );
function mijn_smtp_admin_bar_snelkoppeling( $admin_bar ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $admin_bar->add_node( array(
        'id'    => 'mijn-smtp-testmail',
        'title' => '✉️ Test SMTP',
        'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=mijn_smtp_snelle_testmail' ), 'mijn_smtp_snelle_testmail' ),
        'meta'  => array( 'title' => 'Verstuur direct een testmail naar jezelf' ),
    ) );
}

add_action( 'admin_post_mijn_smtp_snelle_testmail', 'mijn_smtp_verwerk_snelle_testmail' );
function mijn_smtp_verwerk_snelle_testmail() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Onvoldoende rechten.' );
    }
    check_admin_referer( 'mijn_smtp_snelle_testmail' );

    $gebruiker = wp_get_current_user();
    $verzonden = wp_mail( $gebruiker->user_email, 'Test E-mail via Brink SMTP (snelkoppeling)', 'Gefeliciteerd! Als je dit leest, werkt de koppeling.' );

    set_transient( 'mijn_smtp_snelle_testmail_resultaat_' . get_current_user_id(), $verzonden ? 'success' : 'failed', 60 );

    $referer = wp_get_referer();
    $doel = ( $referer && strpos( $referer, admin_url() ) === 0 ) ? $referer : admin_url( 'options-general.php?page=mijn-smtp-instellingen' );
    wp_safe_redirect( $doel );
    exit;
}

add_action( 'admin_notices', 'mijn_smtp_toon_snelle_testmail_resultaat' );
function mijn_smtp_toon_snelle_testmail_resultaat() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $sleutel = 'mijn_smtp_snelle_testmail_resultaat_' . get_current_user_id();
    $resultaat = get_transient( $sleutel );
    if ( ! $resultaat ) {
        return;
    }
    delete_transient( $sleutel );
    if ( 'success' === $resultaat ) {
        echo '<div class="notice notice-success is-dismissible"><p>✅ Testmail (via de adminbalk-snelkoppeling) succesvol verzonden naar je eigen e-mailadres.</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>❌ Testmail via de adminbalk-snelkoppeling is mislukt. Bekijk het logboek voor details.</p></div>';
    }
}

/**
 * FEATURE 3: Microsoft Token Expiry Warning
 */
add_action( 'admin_notices', 'mijn_smtp_check_expiry_notice' );
function mijn_smtp_check_expiry_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $opties = get_option( 'mijn_smtp_options', array() );
    if ( ! empty( $opties['methode'] ) && $opties['methode'] === 'microsoft' && ! empty( $opties['ms_secret_expiry'] ) ) {
        // v3.1 - bugfix: dezelfde tijdzone gebruiken als de rest van de plugin, i.p.v.
        // de kale strtotime() die de PHP-servertijdzone aanhield (kon een dag schelen).
        try {
            $expiry = ( new DateTime( $opties['ms_secret_expiry'], wp_timezone() ) )->getTimestamp();
        } catch ( Exception $e ) {
            return;
        }
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
 * FEATURE (v3.0 - suggestie #31): waarschuwing bij ongebruikelijk hoog dagvolume.
 */
add_action( 'admin_notices', 'mijn_smtp_toon_volume_waarschuwing' );
function mijn_smtp_toon_volume_waarschuwing() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $aantal = get_transient( 'mijn_smtp_volume_waarschuwing_actief' );
    if ( $aantal ) {
        echo '<div class="notice notice-warning"><p>📈 <strong>Brink Multimedia SMTP:</strong> Er zijn vandaag al <strong>' . esc_html( $aantal ) . '</strong> e-mails verzonden — meer dan je ingestelde waarschuwingsdrempel. Controleer of dit klopt (mogelijk misbruik van een formulier of account).</p></div>';
    }
}

/**
 * FEATURE (v3.0 - suggestie #11): waarschuwing bij opeenvolgende mislukte verzendingen.
 */
add_action( 'admin_notices', 'mijn_smtp_toon_server_onbereikbaar_notice' );
function mijn_smtp_toon_server_onbereikbaar_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $fouten = get_transient( 'mijn_smtp_server_onbereikbaar_waarschuwing' );
    if ( $fouten ) {
        echo '<div class="notice notice-error"><p>📡 <strong>Brink Multimedia SMTP:</strong> De laatste <strong>' . esc_html( $fouten ) . '</strong> verzendpogingen zijn achter elkaar mislukt. Controleer je instellingen of de status van je mailserver/-provider.</p></div>';
    }
}
add_action( 'wp_mail_succeeded', 'mijn_smtp_reset_opeenvolgende_fouten' );
function mijn_smtp_reset_opeenvolgende_fouten() {
    if ( (int) get_option( 'mijn_smtp_opeenvolgende_fouten', 0 ) !== 0 ) {
        update_option( 'mijn_smtp_opeenvolgende_fouten', 0, false );
    }
    delete_transient( 'mijn_smtp_server_onbereikbaar_waarschuwing' );
}

/**
 * FEATURE (v3.0 - suggestie #36): detecteer andere actieve SMTP-plugins.
 */
function mijn_smtp_detecteer_conflicterende_plugins() {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $bekende_smtp_plugins = array(
        'wp-mail-smtp/wp_mail_smtp.php' => 'WP Mail SMTP',
        'easy-wp-smtp/easy-wp-smtp.php' => 'Easy WP SMTP',
        'post-smtp/postman-smtp.php'    => 'Post SMTP',
        'fluent-smtp/fluent-smtp.php'   => 'FluentSMTP',
        'wp-smtp/wp-smtp.php'           => 'WP SMTP',
    );
    $gevonden = array();
    foreach ( $bekende_smtp_plugins as $bestand => $naam ) {
        if ( is_plugin_active( $bestand ) ) {
            $gevonden[] = $naam;
        }
    }
    return $gevonden;
}
add_action( 'admin_notices', 'mijn_smtp_toon_conflict_waarschuwing' );
function mijn_smtp_toon_conflict_waarschuwing() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $gevonden = mijn_smtp_detecteer_conflicterende_plugins();
    if ( ! empty( $gevonden ) ) {
        $meervoud = count( $gevonden ) === 1 ? 'is nog een andere SMTP-plugin actief' : 'zijn nog andere SMTP-plugins actief';
        echo '<div class="notice notice-warning"><p>⚠️ <strong>Brink Multimedia SMTP:</strong> Er ' . esc_html( $meervoud ) . ' (' . esc_html( implode( ', ', $gevonden ) ) . '). Dit kan conflicteren met de mailinstellingen van deze plugin — overweeg er één te deactiveren.</p></div>';
    }
}

/**
 * 1. Menu-item + activatie (met cron-planning en onboarding-redirect, v3.0)
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
 * v3.1 - bugfix: gebruikte voorheen strtotime() zonder tijdzone, wat de PHP-
 * servertijdzone aanhoudt in plaats van de tijdzone die in WordPress is ingesteld
 * (Instellingen > Algemeen). Op een server met een andere tijdzone dan de site kwam
 * het rapport daardoor op een ander lokaal tijdstip binnen dan de "08:00" suggereert.
 */
function mijn_smtp_bepaal_volgende_rapport_tijd( $interval ) {
    $nu = new DateTime( 'now', wp_timezone() );
    switch ( $interval ) {
        case 'daily':
            $doel = ( clone $nu )->modify( 'tomorrow' )->setTime( 8, 0, 0 );
            break;
        case 'monthly':
            $doel = ( clone $nu )->modify( 'first day of next month' )->setTime( 8, 0, 0 );
            break;
        case 'weekly':
        default:
            $doel = ( clone $nu )->modify( 'next monday' )->setTime( 8, 0, 0 );
            break;
    }
    return $doel->getTimestamp();
}

register_activation_hook( __FILE__, 'mijn_smtp_activeer_plugin' );
function mijn_smtp_activeer_plugin() {
    if ( ! wp_next_scheduled( 'mijn_smtp_wekelijks_rapport_event' ) ) {
        $opties = get_option( 'mijn_smtp_options', array() );
        $interval = ! empty( $opties['rapport_interval'] ) ? $opties['rapport_interval'] : 'weekly';
        wp_schedule_event( mijn_smtp_bepaal_volgende_rapport_tijd( $interval ), $interval, 'mijn_smtp_wekelijks_rapport_event' );
    }

    // Onboarding (suggestie #39): stuur de beheerder na activatie één keer naar de instellingenpagina.
    if ( ! isset( $_GET['activate-multi'] ) ) {
        set_transient( 'mijn_smtp_redirect_na_activatie_' . get_current_user_id(), true, 60 );
    }
}

add_action( 'admin_init', 'mijn_smtp_onboarding_redirect' );
function mijn_smtp_onboarding_redirect() {
    $sleutel = 'mijn_smtp_redirect_na_activatie_' . get_current_user_id();
    if ( get_transient( $sleutel ) && current_user_can( 'manage_options' ) ) {
        delete_transient( $sleutel );
        wp_safe_redirect( admin_url( 'options-general.php?page=mijn-smtp-instellingen' ) );
        exit;
    }
}

register_deactivation_hook( __FILE__, 'mijn_smtp_deactiveer_cron' );
function mijn_smtp_deactiveer_cron() {
    wp_clear_scheduled_hook( 'mijn_smtp_wekelijks_rapport_event' );
}

register_uninstall_hook( __FILE__, 'mijn_smtp_uninstall' );
function mijn_smtp_uninstall() {
    delete_option( 'mijn_smtp_options' );
    delete_option( 'mijn_smtp_email_logs' );
    delete_option( 'mijn_smtp_wekelijkse_teller' );
    delete_option( 'mijn_smtp_dagelijkse_teller' );
    delete_option( 'mijn_smtp_dagelijkse_teller_datum' );
    delete_option( 'mijn_smtp_opeenvolgende_fouten' );
    delete_transient( 'mijn_smtp_last_log_id' );
    delete_transient( 'mijn_smtp_ms_token_error' );
    delete_transient( 'mijn_smtp_google_token_error' );
    delete_transient( 'mijn_smtp_ms_access_token_cache' );
    delete_transient( 'mijn_smtp_google_access_token_cache' );
    delete_transient( 'mijn_smtp_recente_releases_cache' );
    delete_transient( 'brink_smtp_github_release_cache' );
}

/**
 * 2. Registreer de instellingen + sanitize-callback
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

    $toegestane_methodes = array( 'standaard', 'microsoft', 'google', 'sendgrid' );
    $output['methode'] = ( isset( $input['methode'] ) && in_array( $input['methode'], $toegestane_methodes, true ) ) ? $input['methode'] : 'standaard';

    // Standaard SMTP
    $output['host'] = isset( $input['host'] ) && $input['host'] !== '' ? sanitize_text_field( $input['host'] ) : 'shared01.brabix.nl';
    $output['port'] = isset( $input['port'] ) ? absint( $input['port'] ) : 587;
    $toegestane_secure = array( 'tls', 'starttls', 'ssl', 'none' );
    $output['secure'] = ( isset( $input['secure'] ) && in_array( $input['secure'], $toegestane_secure, true ) ) ? $input['secure'] : 'tls';
    $output['auth'] = ( isset( $input['auth'] ) && $input['auth'] === 'yes' ) ? 'yes' : 'no';
    $output['username'] = isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '';
    $output['password'] = ( isset( $input['password'] ) && $input['password'] !== '' )
        ? $input['password']
        : ( isset( $bestaand['password'] ) ? $bestaand['password'] : '' );

    // Microsoft
    $output['ms_client_id'] = isset( $input['ms_client_id'] ) ? sanitize_text_field( $input['ms_client_id'] ) : '';
    $output['ms_tenant_id'] = isset( $input['ms_tenant_id'] ) ? sanitize_text_field( $input['ms_tenant_id'] ) : '';
    $output['ms_client_secret'] = ( isset( $input['ms_client_secret'] ) && $input['ms_client_secret'] !== '' )
        ? $input['ms_client_secret']
        : ( isset( $bestaand['ms_client_secret'] ) ? $bestaand['ms_client_secret'] : '' );
    $output['ms_secret_expiry'] = isset( $input['ms_secret_expiry'] ) ? sanitize_text_field( $input['ms_secret_expiry'] ) : '';
    $output['ms_refresh_token'] = isset( $bestaand['ms_refresh_token'] ) ? $bestaand['ms_refresh_token'] : '';

    // Google (v3.0 - suggestie #1)
    $output['google_client_id'] = isset( $input['google_client_id'] ) ? sanitize_text_field( $input['google_client_id'] ) : '';
    $output['google_client_secret'] = ( isset( $input['google_client_secret'] ) && $input['google_client_secret'] !== '' )
        ? $input['google_client_secret']
        : ( isset( $bestaand['google_client_secret'] ) ? $bestaand['google_client_secret'] : '' );
    $output['google_refresh_token'] = isset( $bestaand['google_refresh_token'] ) ? $bestaand['google_refresh_token'] : '';

    // SendGrid (v3.0 - suggestie #2)
    $output['sendgrid_api_key'] = ( isset( $input['sendgrid_api_key'] ) && $input['sendgrid_api_key'] !== '' )
        ? $input['sendgrid_api_key']
        : ( isset( $bestaand['sendgrid_api_key'] ) ? $bestaand['sendgrid_api_key'] : '' );

    // Debug & automatische testmail
    $output['debug_mode'] = ! empty( $input['debug_mode'] ) ? 1 : 0;
    $output['auto_test_on_save'] = ! empty( $input['auto_test_on_save'] ) ? 1 : 0;
    if ( $output['auto_test_on_save'] ) {
        set_transient( 'mijn_smtp_trigger_auto_test_' . get_current_user_id(), 1, 60 );
    }

    // Volumewaarschuwing (suggestie #31)
    $output['volume_waarschuwing_drempel'] = isset( $input['volume_waarschuwing_drempel'] ) ? absint( $input['volume_waarschuwing_drempel'] ) : 0;

    // Rapport-interval (suggestie #32) + herplannen indien gewijzigd
    $toegestane_intervallen = array( 'daily', 'weekly', 'monthly' );
    $output['rapport_interval'] = ( isset( $input['rapport_interval'] ) && in_array( $input['rapport_interval'], $toegestane_intervallen, true ) ) ? $input['rapport_interval'] : 'weekly';
    $oude_interval = isset( $bestaand['rapport_interval'] ) ? $bestaand['rapport_interval'] : 'weekly';
    if ( $oude_interval !== $output['rapport_interval'] ) {
        wp_clear_scheduled_hook( 'mijn_smtp_wekelijks_rapport_event' );
        wp_schedule_event( mijn_smtp_bepaal_volgende_rapport_tijd( $output['rapport_interval'] ), $output['rapport_interval'], 'mijn_smtp_wekelijks_rapport_event' );
    }

    // v3.2 - suggestie #10: optioneel mislukte verzendingen meesturen in het rapport
    $output['rapport_inclusief_mislukt'] = ! empty( $input['rapport_inclusief_mislukt'] ) ? 1 : 0;

    // Afzender-overrides per plugin/formulier (suggestie #37)
    $output['from_overrides'] = array();
    if ( ! empty( $input['from_overrides'] ) && is_array( $input['from_overrides'] ) ) {
        foreach ( $input['from_overrides'] as $regel ) {
            $match = isset( $regel['match_email'] ) ? sanitize_email( $regel['match_email'] ) : '';
            $naar  = isset( $regel['from_email'] ) ? sanitize_email( $regel['from_email'] ) : '';
            $naam  = isset( $regel['from_name'] ) ? sanitize_text_field( $regel['from_name'] ) : '';
            if ( $match && $naar ) {
                $output['from_overrides'][] = array( 'match_email' => $match, 'from_email' => $naar, 'from_name' => $naam );
            }
        }
    }

    return $output;
}

/**
 * 2b. Callback afhandeling voor Microsoft- én Google-OAuth (gedeelde flow, v3.0)
 */
add_action( 'admin_init', 'mijn_smtp_handle_oauth_callback' );
function mijn_smtp_handle_oauth_callback() {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'mijn-smtp-instellingen' ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_GET['code'] ) && isset( $_GET['state'] ) ) {
        $state_ontvangen = wp_unslash( $_GET['state'] );
        $delim_pos = strpos( $state_ontvangen, ':' );
        if ( false === $delim_pos ) {
            wp_die( 'Ongeldige autorisatie-aanvraag.', 'Beveiligingscontrole mislukt', array( 'response' => 403 ) );
        }
        $provider = substr( $state_ontvangen, 0, $delim_pos );
        $state_token = substr( $state_ontvangen, $delim_pos + 1 );

        if ( ! in_array( $provider, array( 'microsoft', 'google' ), true ) ) {
            wp_die( 'Onbekende OAuth-provider.', 'Beveiligingscontrole mislukt', array( 'response' => 403 ) );
        }

        $state_key = 'mijn_smtp_oauth_state_' . $provider . '_' . get_current_user_id();
        $verwachte_state = get_transient( $state_key );
        delete_transient( $state_key );

        if ( ! $verwachte_state || ! hash_equals( $verwachte_state, $state_token ) ) {
            wp_die(
                'Deze koppelaanvraag is ongeldig of verlopen. Ga terug naar de instellingenpagina en probeer opnieuw te koppelen.',
                'Beveiligingscontrole mislukt',
                array( 'response' => 403 )
            );
        }

        $opties = get_option( 'mijn_smtp_options', array() );
        $code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        $redirect_uri = admin_url( 'options-general.php?page=mijn-smtp-instellingen' );

        if ( 'microsoft' === $provider ) {
            $tenant = ! empty( $opties['ms_tenant_id'] ) ? $opties['ms_tenant_id'] : 'common';
            $url = 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/token';
            $body = array(
                'client_id'     => isset( $opties['ms_client_id'] ) ? $opties['ms_client_id'] : '',
                'client_secret' => isset( $opties['ms_client_secret'] ) ? $opties['ms_client_secret'] : '',
                'code'          => $code,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            );
            $token_veld = 'ms_refresh_token';
        } else {
            $url = 'https://oauth2.googleapis.com/token';
            $body = array(
                'client_id'     => isset( $opties['google_client_id'] ) ? $opties['google_client_id'] : '',
                'client_secret' => isset( $opties['google_client_secret'] ) ? $opties['google_client_secret'] : '',
                'code'          => $code,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            );
            $token_veld = 'google_refresh_token';
        }

        $response = wp_remote_post( $url, array( 'body' => $body, 'timeout' => 15 ) );
        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $data['refresh_token'] ) ) {
                $opties[ $token_veld ] = $data['refresh_token'];
                update_option( 'mijn_smtp_options', $opties, false );
                wp_safe_redirect( admin_url( 'options-general.php?page=mijn-smtp-instellingen&oauth=success' ) );
                exit;
            }
        }
        wp_safe_redirect( admin_url( 'options-general.php?page=mijn-smtp-instellingen&oauth=failed' ) );
        exit;
    }

    if ( isset( $_GET['disconnect_ms'] ) ) {
        check_admin_referer( 'mijn_smtp_disconnect_ms' );
        $opties = get_option( 'mijn_smtp_options', array() );
        unset( $opties['ms_refresh_token'] );
        update_option( 'mijn_smtp_options', $opties, false );
        wp_safe_redirect( admin_url( 'options-general.php?page=mijn-smtp-instellingen' ) );
        exit;
    }

    if ( isset( $_GET['disconnect_google'] ) ) {
        check_admin_referer( 'mijn_smtp_disconnect_google' );
        $opties = get_option( 'mijn_smtp_options', array() );
        unset( $opties['google_refresh_token'] );
        update_option( 'mijn_smtp_options', $opties, false );
        wp_safe_redirect( admin_url( 'options-general.php?page=mijn-smtp-instellingen' ) );
        exit;
    }
}

/**
 * Verstuurt een testmail en toont direct het resultaat. Gedeeld door de handmatige
 * knop en de automatische testmail-na-opslaan functie (suggestie #12).
 */
function mijn_smtp_stuur_en_toon_testmail( $naar ) {
    $naar = sanitize_email( $naar );
    if ( empty( $naar ) ) {
        echo '<div class="notice notice-error is-dismissible"><p>❌ Geen geldig e-mailadres om een testmail naartoe te sturen.</p></div>';
        return;
    }

    global $brink_smtp_live_error;
    $brink_smtp_live_error = '';
    $handler = function( $error ) {
        global $brink_smtp_live_error;
        $brink_smtp_live_error = $error->get_error_message();
    };
    add_action( 'wp_mail_failed', $handler );

    ob_start();
    $verzonden = wp_mail( $naar, 'Test E-mail via Brink SMTP', 'Gefeliciteerd! Als je dit leest, is de koppeling succesvol.' );
    ob_get_clean();

    remove_action( 'wp_mail_failed', $handler );

    if ( $verzonden ) {
        echo '<div class="notice notice-success is-dismissible"><p>✅ Test e-mail succesvol verzonden naar <strong>' . esc_html( $naar ) . '</strong>!</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible" style="padding-bottom: 10px;">';
        echo '<p>❌ <strong>Fout bij verzenden:</strong> ' . esc_html( $brink_smtp_live_error ?: 'Onbekende fout' ) . '</p>';

        $oauth_fout = get_transient( 'mijn_smtp_ms_token_error' ) ?: get_transient( 'mijn_smtp_google_token_error' );
        if ( $oauth_fout ) {
            echo '<div style="margin-top: 10px; padding: 10px; border-left: 4px solid var(--brink-error,#d63638); background: var(--brink-card-bg,#fff);">';
            echo '<strong>⚠️ OAuth Token Fout (je mag niet inloggen):</strong><br>';
            echo '<code>' . esc_html( $oauth_fout ) . '</code>';
            echo '</div>';
        }

        echo '<p style="color: var(--brink-error,#d63638); font-weight:bold; margin-top: 10px;">Bekijk het Logboek hieronder voor de specifieke code (zet evt. Debug-logging aan voor meer detail).</p>';
        echo '</div>';

        delete_transient( 'mijn_smtp_ms_token_error' );
        delete_transient( 'mijn_smtp_google_token_error' );
    }
}

/**
 * 3. Ontwerp van de instellingenpagina in het dashboard
 */
function mijn_smtp_instellingen_pagina() {
    global $brink_smtp_global_debug;

    $opties = get_option( 'mijn_smtp_options', array() );

    // --- TEST E-MAIL AFHANDELING ---
    if ( isset( $_POST['mijn_smtp_test_submit'] ) && current_user_can( 'manage_options' ) ) {
        check_admin_referer( 'mijn_smtp_test_mail_action', 'mijn_smtp_test_nonce' );
        mijn_smtp_stuur_en_toon_testmail( wp_unslash( $_POST['mijn_smtp_test_email'] ) );
    }

    // --- LOGBOEK LEGEN ---
    if ( isset( $_POST['mijn_smtp_clear_log'] ) && current_user_can( 'manage_options' ) ) {
        check_admin_referer( 'mijn_smtp_clear_log_action', 'mijn_smtp_clear_log_nonce' );
        update_option( 'mijn_smtp_email_logs', array(), false );
        echo '<div class="notice notice-success is-dismissible"><p>✅ Logboek is geleegd.</p></div>';
    }

    // --- SPF/DKIM-CONTROLE (suggestie #11, alleen op handmatig verzoek) ---
    if ( isset( $_POST['mijn_smtp_check_dns'] ) && current_user_can( 'manage_options' ) ) {
        check_admin_referer( 'mijn_smtp_check_dns_action', 'mijn_smtp_check_dns_nonce' );
        $domein_voor_check = ! empty( $opties['from_email'] ) ? substr( strrchr( $opties['from_email'], '@' ), 1 ) : '';
        $methode_voor_check = isset( $opties['methode'] ) ? $opties['methode'] : 'standaard';
        if ( $domein_voor_check ) {
            mijn_smtp_dns_check( $domein_voor_check, $methode_voor_check, true );
        }
    }

    // --- FORCEER AUTOLOAD=FALSE NA EEN SETTINGS-SAVE ---
    if ( isset( $_GET['settings-updated'] ) && current_user_can( 'manage_options' ) ) {
        $huidige_opties = get_option( 'mijn_smtp_options', array() );
        update_option( 'mijn_smtp_options', $huidige_opties, false );
        $opties = $huidige_opties;
    }

    // --- AUTOMATISCHE TESTMAIL NA OPSLAAN (suggestie #12) ---
    $auto_test_sleutel = 'mijn_smtp_trigger_auto_test_' . get_current_user_id();
    if ( get_transient( $auto_test_sleutel ) && current_user_can( 'manage_options' ) ) {
        delete_transient( $auto_test_sleutel );
        $auto_ontvanger = ! empty( $opties['from_email'] ) ? $opties['from_email'] : get_option( 'admin_email' );
        echo '<p style="font-style:italic;">Automatische testmail wordt verstuurd naar ' . esc_html( $auto_ontvanger ) . ' (je hebt dit aangevinkt bij "Automatisch testen")…</p>';
        mijn_smtp_stuur_en_toon_testmail( $auto_ontvanger );
    }

    $methode  = isset( $opties['methode'] ) ? $opties['methode'] : 'standaard';
    $from     = isset( $opties['from_email'] ) ? $opties['from_email'] : get_option( 'admin_email' );
    $fromnaam = isset( $opties['from_name'] ) ? $opties['from_name'] : get_bloginfo( 'name' );
    $huidige_teller = (int) get_option( 'mijn_smtp_wekelijkse_teller', 0 );
    ?>
    <style>
        :root {
            --brink-card-bg: #fff;
            --brink-card-border: #ccd0d4;
            --brink-text: #1d2327;
            --brink-text-muted: #646970;
            --brink-accent: #2271b1;
            --brink-success: #46b450;
            --brink-error: #d63638;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --brink-card-bg: #1e1e1e;
                --brink-card-border: #3c3c3c;
                --brink-text: #e0e0e0;
                --brink-text-muted: #a7aaad;
                --brink-accent: #4f94d4;
                --brink-success: #4caf50;
                --brink-error: #f26c6c;
            }
        }
        .brink-smtp-card { background: var(--brink-card-bg); color: var(--brink-text); border: 1px solid var(--brink-card-border); padding: 15px; }
        .brink-smtp-card--accent { border-left: 4px solid var(--brink-accent); }
        .brink-smtp-card table.form-table th,
        .brink-smtp-card table.form-table td { color: var(--brink-text); }
    </style>

    <div class="wrap">
        <h2>Brink Multimedia SMTP Instellingen</h2>

        <?php if ( empty( $opties ) ) : // Onboarding-welkomstblok (suggestie #39) ?>
            <div class="brink-smtp-card brink-smtp-card--accent" style="margin-bottom:20px;">
                <h3 style="margin-top:0;">👋 Welkom bij Brink Multimedia SMTP</h3>
                <p>Zo ben je in drie stappen klaar:</p>
                <ol>
                    <li>Kies hieronder je mailmethode (Standaard SMTP, Microsoft, Google of SendGrid).</li>
                    <li>Vul de bijbehorende gegevens in en klik op "Instellingen Opslaan".</li>
                    <li>Verstuur een testmail onderaan deze pagina om te controleren of alles werkt.</li>
                </ol>
            </div>
        <?php endif; ?>

        <div class="brink-smtp-card brink-smtp-card--accent" style="margin-bottom: 20px;">
            <h3 style="margin-top: 0;">📊 Wekelijkse Statistieken</h3>
            <p>Er zijn deze week <strong><?php echo esc_html( $huidige_teller ); ?></strong> e-mails verzonden via deze plugin.<br>
            <em>(Op basis van je gekozen rapport-interval ontvangt de beheerder hier een overzicht van, waarna deze teller wordt gereset).</em></p>
        </div>

        <div class="brink-smtp-card" style="margin-bottom: 20px;">
            <h3 style="margin-top:0;">Verzonden vs. mislukt (laatste 7 dagen)</h3>
            <?php echo mijn_smtp_render_dag_grafiek( mijn_smtp_verzamel_dagelijkse_stats( 7 ) ); ?>
            <p style="font-size:12px; color: var(--brink-text-muted);">🟩 Verzonden &nbsp; 🟥 Mislukt</p>
        </div>

        <div class="brink-smtp-card" style="margin-bottom: 20px;">
            <h3 style="margin-top:0;">SPF/DKIM-controle</h3>
            <?php
            $domein_voor_check = ! empty( $opties['from_email'] ) ? substr( strrchr( $opties['from_email'], '@' ), 1 ) : '';
            if ( ! $domein_voor_check ) : ?>
                <p class="description">Vul eerst een afzender e-mailadres in om dit te kunnen controleren.</p>
            <?php else :
                $dns_resultaat = mijn_smtp_dns_check( $domein_voor_check, $methode ); ?>
                <?php if ( $dns_resultaat ) : ?>
                    <p>
                        SPF voor <strong><?php echo esc_html( $domein_voor_check ); ?></strong>: <?php echo $dns_resultaat['spf'] ? '✅ gevonden' : '⚠️ geen SPF-record (v=spf1) gevonden'; ?><br>
                        DKIM: <?php echo $dns_resultaat['dkim'] ? '✅ gevonden (selector: ' . esc_html( $dns_resultaat['dkim_selector'] ) . ')' : '⚠️ geen bekende DKIM-selector gevonden'; ?>
                    </p>
                    <p class="description">Laatst gecontroleerd: <?php echo esc_html( wp_date( 'd-m-Y H:i', $dns_resultaat['tijd'] ) ); ?>. Best-effort controle — een ontbrekend resultaat betekent niet altijd een probleem (bijv. bij een eigen DKIM-selector). Controleer bij twijfel handmatig via je DNS-provider.</p>
                <?php else : ?>
                    <p class="description">Nog niet gecontroleerd.</p>
                <?php endif; ?>
                <form method="post" action="">
                    <?php wp_nonce_field( 'mijn_smtp_check_dns_action', 'mijn_smtp_check_dns_nonce' ); ?>
                    <button type="submit" name="mijn_smtp_check_dns" class="button">SPF/DKIM nu controleren</button>
                </form>
            <?php endif; ?>
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
                            <option value="google" <?php selected( $methode, 'google' ); ?>>Google / Gmail (OAuth 2.0)</option>
                            <option value="sendgrid" <?php selected( $methode, 'sendgrid' ); ?>>SendGrid (API)</option>
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
                        <p class="description">Staat standaard uit. Regels die mogelijk inloggegevens bevatten worden automatisch verborgen.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Automatisch testen</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mijn_smtp_options[auto_test_on_save]" value="1" <?php checked( ! empty( $opties['auto_test_on_save'] ) ); ?> />
                            Verstuur automatisch een testmail (naar het afzenderadres) direct na het opslaan van instellingen
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Waarschuwingsdrempel per dag</th>
                    <td>
                        <input type="number" min="0" name="mijn_smtp_options[volume_waarschuwing_drempel]" value="<?php echo esc_attr( $opties['volume_waarschuwing_drempel'] ?? 0 ); ?>" class="small-text" />
                        <p class="description">Toon een waarschuwing als er op één dag meer dan dit aantal e-mails wordt verzonden. 0 = uitgeschakeld.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Rapport-interval</th>
                    <td>
                        <select name="mijn_smtp_options[rapport_interval]">
                            <option value="daily" <?php selected( $opties['rapport_interval'] ?? 'weekly', 'daily' ); ?>>Dagelijks</option>
                            <option value="weekly" <?php selected( $opties['rapport_interval'] ?? 'weekly', 'weekly' ); ?>>Wekelijks (maandag)</option>
                            <option value="monthly" <?php selected( $opties['rapport_interval'] ?? 'weekly', 'monthly' ); ?>>Maandelijks</option>
                        </select>
                        <br>
                        <label style="margin-top: 6px; display: inline-block;">
                            <input type="checkbox" name="mijn_smtp_options[rapport_inclusief_mislukt]" value="1" <?php checked( ! empty( $opties['rapport_inclusief_mislukt'] ) ); ?> />
                            Vermeld ook mislukte verzendingen (met reden) in het rapport
                        </label>
                        <p class="description">Let op: het logboek bewaart maximaal 7 dagen, dus bij een maandelijks rapport worden alleen de mislukkingen uit de laatste 7 dagen getoond (max. 20 regels).</p>
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
                    <summary style="font-weight: bold; cursor: pointer; color: #2271b1; outline: none;">📖 Handleiding: Hoe koppel je Microsoft Entra ID (Azure)?</summary>
                    <div style="margin-top: 15px; font-size: 13px; line-height: 1.5;">
                        <ol style="margin-left: 20px; list-style-type: decimal;">
                            <li>Ga naar de <a href="https://entra.microsoft.com/" target="_blank">Microsoft Entra admin center</a> en log in als beheerder.</li>
                            <li>Ga naar <strong>Toepassingen > App-registraties > Nieuwe registratie</strong>.</li>
                            <li>Geef de app een naam (bijv. "Website SMTP").</li>
                            <li>Bij <strong>Omleidings-URI</strong> kies je <strong>Web</strong> en plak je exact:
                                <br><code style="user-select: all; display: inline-block; padding: 5px; background: #fff; border: 1px solid #c3c4c7; margin: 5px 0;"><?php echo esc_html( admin_url( 'options-general.php?page=mijn-smtp-instellingen' ) ); ?></code>
                            </li>
                            <li>Kopieer de <strong>Toepassings-id (client)</strong> en <strong>Map-id (tenant)</strong> naar de velden hieronder.</li>
                            <li>Ga naar <strong>API-machtigingen > Een machtiging toevoegen > Microsoft Graph > Gedelegeerde machtigingen</strong> en vink <code>SMTP.Send</code> en <code>offline_access</code> aan.</li>
                            <li><strong>CRUCIAAL:</strong> klik op "Beheerderstoestemming verlenen".</li>
                            <li>Ga naar <strong>Certificaten & geheimen > Nieuw clientgeheim</strong> en kopieer de <strong>Waarde</strong> naar het veld hieronder.</li>
                        </ol>
                    </div>
                </details>

                <?php if ( isset( $_GET['oauth'] ) && $_GET['oauth'] === 'success' ) : ?>
                    <div class="notice notice-success inline" style="margin-bottom:15px;"><p>✅ Succesvol gekoppeld!</p></div>
                <?php elseif ( isset( $_GET['oauth'] ) && $_GET['oauth'] === 'failed' ) : ?>
                    <div class="notice notice-error inline" style="margin-bottom:15px;"><p>❌ Koppelen mislukt. Controleer je gegevens en of de Redirect URI exact overeenkomt.</p></div>
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Application (client) ID</th>
                        <td><input type="text" name="mijn_smtp_options[ms_client_id]" value="<?php echo esc_attr( $opties['ms_client_id'] ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Directory (tenant) ID</th>
                        <td><input type="text" name="mijn_smtp_options[ms_tenant_id]" value="<?php echo esc_attr( $opties['ms_tenant_id'] ?? '' ); ?>" class="regular-text" /> <br><small><em>(Laat leeg of vul 'common' in)</em></small></td>
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
                        $redirect_uri = rawurlencode( admin_url( 'options-general.php?page=mijn-smtp-instellingen' ) );
                        $scope = rawurlencode( 'offline_access https://outlook.office.com/SMTP.Send' );
                        $state_raw = wp_generate_password( 32, false );
                        set_transient( 'mijn_smtp_oauth_state_microsoft_' . get_current_user_id(), $state_raw, 10 * MINUTE_IN_SECONDS );
                        $state = rawurlencode( 'microsoft:' . $state_raw );
                        $auth_url = "https://login.microsoftonline.com/" . rawurlencode( ! empty( $opties['ms_tenant_id'] ) ? $opties['ms_tenant_id'] : 'common' ) . "/oauth2/v2.0/authorize?client_id=" . rawurlencode( $opties['ms_client_id'] ) . "&response_type=code&redirect_uri={$redirect_uri}&response_mode=query&scope={$scope}&state={$state}";
                        ?>
                        <div style="margin-top: 20px; padding: 15px; border-left: 4px solid #f56e28; background: var(--brink-card-bg,#fff);">
                            <strong>Stap 2: Autoriseer de App</strong><br>
                            <p>Sla eerst je instellingen op. Klik daarna op deze knop om Microsoft toestemming te geven.</p>
                            <a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary">Koppel met Microsoft</a>
                        </div>
                    <?php else: ?>
                        <div style="margin-top: 20px; padding: 15px; border-left: 4px solid var(--brink-success,#46b450); background: var(--brink-card-bg,#fff);">
                            <span style="color: var(--brink-success,green); font-weight: bold; font-size: 16px;">✅ Succesvol verbonden met Microsoft</span>
                            <p>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=mijn-smtp-instellingen&disconnect_ms=1' ), 'mijn_smtp_disconnect_ms' ) ); ?>" style="color: var(--brink-error,#d63638);">Verbreek koppeling</a>
                            </p>
                        </div>
                    <?php endif;
                else: ?>
                    <p style="color: var(--brink-text-muted,#888);"><em>Vul eerst de ID's en Secret in en klik op "Instellingen Opslaan".</em></p>
                <?php endif; ?>
            </div>

            <!-- GOOGLE INSTELLINGEN (v3.0 - suggestie #1) -->
            <div id="sectie_google" style="<?php echo $methode === 'google' ? 'display:block;' : 'display:none;'; ?>">
                <h3>Google / Gmail (OAuth 2.0) API Gegevens</h3>

                <details style="background: #f0f6fc; border: 1px solid #c3c4c7; padding: 10px 15px; margin-bottom: 20px; border-radius: 4px;">
                    <summary style="font-weight: bold; cursor: pointer; color: #2271b1; outline: none;">📖 Handleiding: Hoe koppel je een Google-account?</summary>
                    <div style="margin-top: 15px; font-size: 13px; line-height: 1.5;">
                        <ol style="margin-left: 20px; list-style-type: decimal;">
                            <li>Ga naar de <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console – Credentials</a> (maak zo nodig eerst een project aan).</li>
                            <li>Zet de <strong>Gmail API</strong> aan via "API's en services > Bibliotheek".</li>
                            <li>Configureer het <strong>OAuth-toestemmingsscherm</strong> en voeg jezelf toe als testgebruiker als de app nog in testmodus staat.</li>
                            <li>Maak een <strong>OAuth-client-ID</strong> aan van het type <strong>Webtoepassing</strong>.</li>
                            <li>Voeg bij <strong>Geautoriseerde omleidings-URI's</strong> exact deze URL toe:
                                <br><code style="user-select: all; display: inline-block; padding: 5px; background: #fff; border: 1px solid #c3c4c7; margin: 5px 0;"><?php echo esc_html( admin_url( 'options-general.php?page=mijn-smtp-instellingen' ) ); ?></code>
                            </li>
                            <li>Kopieer de <strong>Client-ID</strong> en <strong>Clientgeheim</strong> naar de velden hieronder.</li>
                        </ol>
                    </div>
                </details>

                <table class="form-table">
                    <tr>
                        <th scope="row">Client ID</th>
                        <td><input type="text" name="mijn_smtp_options[google_client_id]" value="<?php echo esc_attr( $opties['google_client_id'] ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Client Secret</th>
                        <td>
                            <input type="password" name="mijn_smtp_options[google_client_secret]" value="" class="regular-text" autocomplete="new-password"
                                placeholder="<?php echo ! empty( $opties['google_client_secret'] ) ? '•••••••• (laat leeg om ongewijzigd te laten)' : 'Voer client secret in'; ?>" />
                        </td>
                    </tr>
                </table>

                <?php
                if ( ! empty( $opties['google_client_id'] ) && ! empty( $opties['google_client_secret'] ) ) :
                    if ( empty( $opties['google_refresh_token'] ) ) :
                        $redirect_uri = rawurlencode( admin_url( 'options-general.php?page=mijn-smtp-instellingen' ) );
                        $scope = rawurlencode( 'https://mail.google.com/' );
                        $state_raw = wp_generate_password( 32, false );
                        set_transient( 'mijn_smtp_oauth_state_google_' . get_current_user_id(), $state_raw, 10 * MINUTE_IN_SECONDS );
                        $state = rawurlencode( 'google:' . $state_raw );
                        $auth_url = "https://accounts.google.com/o/oauth2/v2/auth?client_id=" . rawurlencode( $opties['google_client_id'] ) . "&response_type=code&redirect_uri={$redirect_uri}&scope={$scope}&access_type=offline&prompt=consent&state={$state}";
                        ?>
                        <div style="margin-top: 20px; padding: 15px; border-left: 4px solid #f56e28; background: var(--brink-card-bg,#fff);">
                            <strong>Stap 2: Autoriseer de App</strong><br>
                            <p>Sla eerst je instellingen op. Klik daarna op deze knop om Google toestemming te geven.</p>
                            <a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary">Koppel met Google</a>
                        </div>
                    <?php else: ?>
                        <div style="margin-top: 20px; padding: 15px; border-left: 4px solid var(--brink-success,#46b450); background: var(--brink-card-bg,#fff);">
                            <span style="color: var(--brink-success,green); font-weight: bold; font-size: 16px;">✅ Succesvol verbonden met Google</span>
                            <p>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=mijn-smtp-instellingen&disconnect_google=1' ), 'mijn_smtp_disconnect_google' ) ); ?>" style="color: var(--brink-error,#d63638);">Verbreek koppeling</a>
                            </p>
                        </div>
                    <?php endif;
                else: ?>
                    <p style="color: var(--brink-text-muted,#888);"><em>Vul eerst de ID's en Secret in en klik op "Instellingen Opslaan".</em></p>
                <?php endif; ?>
            </div>

            <!-- SENDGRID INSTELLINGEN (v3.0 - suggestie #2) -->
            <div id="sectie_sendgrid" style="<?php echo $methode === 'sendgrid' ? 'display:block;' : 'display:none;'; ?>">
                <h3>SendGrid (API)</h3>
                <p><em>Verzending verloopt via de SendGrid HTTP-API (niet via SMTP) — geen server-host of poort nodig.</em></p>
                <table class="form-table">
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="password" name="mijn_smtp_options[sendgrid_api_key]" value="" class="regular-text" autocomplete="new-password"
                                placeholder="<?php echo ! empty( $opties['sendgrid_api_key'] ) ? '•••••••• (laat leeg om ongewijzigd te laten)' : 'SG.xxxxxxxx'; ?>" />
                            <p class="description">Maak een key aan via SendGrid → Settings → API Keys, met minimaal "Mail Send"-rechten.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <hr>

            <!-- AFZENDER-OVERRIDES (v3.0 - suggestie #37) -->
            <h3>Afzender-overrides per plugin/formulier</h3>
            <p class="description">Sommige plugins (bijv. WooCommerce of een contactformulier) sturen mail met hun eigen vaste afzenderadres. Hier kun je per herkend adres een ander afzenderadres/-naam afdwingen, los van de algemene forcering hierboven.</p>
            <table class="wp-list-table widefat fixed striped" id="brink-smtp-overrides-tabel">
                <thead>
                    <tr>
                        <th>Origineel afzenderadres (van de plugin)</th>
                        <th>Gebruik dit e-mailadres</th>
                        <th>Gebruik deze naam</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $overrides = ! empty( $opties['from_overrides'] ) && is_array( $opties['from_overrides'] ) ? array_values( $opties['from_overrides'] ) : array( array( 'match_email' => '', 'from_email' => '', 'from_name' => '' ) );
                    foreach ( $overrides as $i => $regel ) : ?>
                        <tr>
                            <td><input type="email" name="mijn_smtp_options[from_overrides][<?php echo (int) $i; ?>][match_email]" value="<?php echo esc_attr( $regel['match_email'] ?? '' ); ?>" class="regular-text" placeholder="wordpress@voorbeeld.nl" /></td>
                            <td><input type="email" name="mijn_smtp_options[from_overrides][<?php echo (int) $i; ?>][from_email]" value="<?php echo esc_attr( $regel['from_email'] ?? '' ); ?>" class="regular-text" /></td>
                            <td><input type="text" name="mijn_smtp_options[from_overrides][<?php echo (int) $i; ?>][from_name]" value="<?php echo esc_attr( $regel['from_name'] ?? '' ); ?>" class="regular-text" /></td>
                            <td><button type="button" class="button brink-smtp-verwijder-regel">Verwijder</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" id="brink-smtp-voeg-regel-toe" class="button">+ Regel toevoegen</button></p>

            <?php submit_button( 'Instellingen Opslaan' ); ?>
        </form>

        <hr>

        <!-- TEST E-MAIL FORMULIER -->
        <div class="brink-smtp-card" style="margin-top: 30px;">
            <h3 style="margin-top: 0;">Test Je Instellingen</h3>
            <p>Vul een e-mailadres in om te controleren of de bovenstaande instellingen werken (sla eerst je instellingen op!).</p>
            <form method="post" action="">
                <?php wp_nonce_field( 'mijn_smtp_test_mail_action', 'mijn_smtp_test_nonce' ); ?>
                <input type="email" name="mijn_smtp_test_email" class="regular-text" placeholder="jouw@email.nl" required />
                <button type="submit" name="mijn_smtp_test_submit" class="button button-secondary">Verstuur Test E-mail</button>
            </form>
        </div>

        <!-- E-MAIL LOGBOEK -->
        <div class="brink-smtp-card" style="margin-top: 30px;">
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
                                        <span style="color: var(--brink-success,#46b450); font-weight: bold;">✅ Verzonden</span>
                                    <?php else: ?>
                                        <span style="color: var(--brink-error,#d63638); font-weight: bold;">❌ Mislukt</span>
                                        <br><small style="color: var(--brink-error,#d63638);"><?php echo esc_html( $log['error'] ); ?></small>

                                        <?php if ( ! empty( $log['server_log'] ) ) : ?>
                                            <details style="margin-top: 5px;">
                                                <summary style="cursor: pointer; font-size: 11px; color: var(--brink-accent,#2271b1);">Bekijk Server Log</summary>
                                                <pre style="margin-top: 5px; white-space: pre-wrap; font-size: 10px; background: var(--brink-card-bg,#f6f7f7); padding: 5px; max-height: 150px; overflow-y: auto; color: var(--brink-error,#d63638); border: 1px solid var(--brink-card-border,#ccc);"><?php echo esc_html( $log['server_log'] ); ?></pre>
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

        <!-- RECENTE UPDATES (v3.2 - suggestie #20) -->
        <div class="brink-smtp-card" style="margin-top: 30px;">
            <h3 style="margin-top:0;">Recente updates</h3>
            <?php
            $releases = mijn_smtp_haal_recente_releases_op();
            if ( empty( $releases ) ) : ?>
                <p class="description">Kon geen recente releases ophalen van GitHub.</p>
            <?php else : ?>
                <ul style="margin-left: 18px; list-style: disc;">
                    <?php foreach ( $releases as $release ) : ?>
                        <li>
                            <strong><?php echo esc_html( $release['tag'] ); ?></strong>
                            <?php if ( ! empty( $release['datum'] ) ) : ?>
                                <span style="color: var(--brink-text-muted);">(<?php echo esc_html( wp_date( 'd-m-Y', strtotime( $release['datum'] ) ) ); ?>)</span>
                            <?php endif; ?>
                            <?php if ( ! empty( $release['titel'] ) ) : ?>
                                — <?php echo esc_html( $release['titel'] ); ?>
                            <?php endif; ?>
                            <?php if ( ! empty( $release['url'] ) ) : ?>
                                — <a href="<?php echo esc_url( $release['url'] ); ?>" target="_blank" rel="noopener noreferrer">bekijk</a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="description">Huidige geïnstalleerde versie: <?php echo esc_html( BRINK_SMTP_VERSION ); ?></p>
        </div>

    </div>

    <script>
        (function() {
            function toonSectie() {
                var methode = document.getElementById('mijn_smtp_methode').value;
                var secties = { standaard: 'sectie_standaard', microsoft: 'sectie_microsoft', google: 'sectie_google', sendgrid: 'sectie_sendgrid' };
                for (var sleutel in secties) {
                    var el = document.getElementById(secties[sleutel]);
                    if (el) { el.style.display = (sleutel === methode) ? 'block' : 'none'; }
                }
            }
            document.getElementById('mijn_smtp_methode').addEventListener('change', toonSectie);

            var tabel = document.getElementById('brink-smtp-overrides-tabel');
            document.getElementById('brink-smtp-voeg-regel-toe').addEventListener('click', function() {
                var tbody = tabel.querySelector('tbody');
                var index = tbody.querySelectorAll('tr').length;
                var rij = document.createElement('tr');
                rij.innerHTML =
                    '<td><input type="email" name="mijn_smtp_options[from_overrides][' + index + '][match_email]" class="regular-text" placeholder="wordpress@voorbeeld.nl" /></td>' +
                    '<td><input type="email" name="mijn_smtp_options[from_overrides][' + index + '][from_email]" class="regular-text" /></td>' +
                    '<td><input type="text" name="mijn_smtp_options[from_overrides][' + index + '][from_name]" class="regular-text" /></td>' +
                    '<td><button type="button" class="button brink-smtp-verwijder-regel">Verwijder</button></td>';
                tbody.appendChild(rij);
            });
            tabel.addEventListener('click', function(e) {
                if (e.target.classList.contains('brink-smtp-verwijder-regel')) {
                    e.target.closest('tr').remove();
                }
            });
        })();
    </script>
    <?php
}

/**
 * 5. Teller en Logboek voor het bijhouden van verzonden e-mails
 * v3.0: houdt ook een dagteller bij t.b.v. de volumewaarschuwing (suggestie #31).
 */
add_filter( 'wp_mail', 'mijn_smtp_verwerk_mail_log' );
function mijn_smtp_verwerk_mail_log( $args ) {
    $huidige_teller = (int) get_option( 'mijn_smtp_wekelijkse_teller', 0 );
    update_option( 'mijn_smtp_wekelijkse_teller', $huidige_teller + 1, false );

    // Dagelijkse teller + volumewaarschuwing
    $vandaag = current_time( 'Y-m-d' );
    $laatste_datum = get_option( 'mijn_smtp_dagelijkse_teller_datum', '' );
    if ( $laatste_datum !== $vandaag ) {
        update_option( 'mijn_smtp_dagelijkse_teller', 0, false );
        update_option( 'mijn_smtp_dagelijkse_teller_datum', $vandaag, false );
        delete_transient( 'mijn_smtp_volume_waarschuwing_verstuurd' );
    }
    $dag_teller = (int) get_option( 'mijn_smtp_dagelijkse_teller', 0 ) + 1;
    update_option( 'mijn_smtp_dagelijkse_teller', $dag_teller, false );

    $opties = get_option( 'mijn_smtp_options', array() );
    $drempel = (int) ( $opties['volume_waarschuwing_drempel'] ?? 0 );
    if ( $drempel > 0 && $dag_teller > $drempel && ! get_transient( 'mijn_smtp_volume_waarschuwing_verstuurd' ) ) {
        set_transient( 'mijn_smtp_volume_waarschuwing_verstuurd', 1, DAY_IN_SECONDS );
        set_transient( 'mijn_smtp_volume_waarschuwing_actief', $dag_teller, DAY_IN_SECONDS );
        // v3.1 - bugfix: niet meer direct wp_mail() aanroepen vanuit deze 'wp_mail'-filter
        // zelf (dat telde de waarschuwingsmail zelf weer mee en kon bij een mislukte
        // verzending van de waarschuwing onnodig extra fouten in het logboek zetten).
        // In plaats daarvan één keer uitgesteld via cron.
        wp_schedule_single_event( time() + 1, 'mijn_smtp_volume_alert_event', array( $dag_teller, $drempel ) );
    }

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
 * 5b. Log markeren als mislukt + tellen van opeenvolgende fouten (suggestie #11)
 */
add_action( 'wp_mail_failed', 'mijn_smtp_markeer_log_mislukt', 10, 1 );
function mijn_smtp_markeer_log_mislukt( $error ) {
    global $brink_smtp_global_debug;

    $fouten = (int) get_option( 'mijn_smtp_opeenvolgende_fouten', 0 ) + 1;
    update_option( 'mijn_smtp_opeenvolgende_fouten', $fouten, false );
    $drempel = 3;
    if ( $fouten >= $drempel ) {
        set_transient( 'mijn_smtp_server_onbereikbaar_waarschuwing', $fouten, DAY_IN_SECONDS );
    }

    // v3.1 - bugfix: de specifieke OAuth-foutmelding (bijv. "invalid_grant") werd tot nu
    // toe alleen getoond bij een hándmatige testmail, niet in het permanente logboek —
    // daar stond voor stille (achtergrond-)mails alleen de generieke PHPMailer-melding
    // ("Could not authenticate"). Nu wordt de specifieke reden, indien bekend, toegevoegd.
    $foutmelding = $error->get_error_message();
    $oauth_fout = get_transient( 'mijn_smtp_ms_token_error' ) ?: get_transient( 'mijn_smtp_google_token_error' );
    if ( $oauth_fout ) {
        $foutmelding .= ' (OAuth: ' . $oauth_fout . ')';
    }

    $last_id = get_transient( 'mijn_smtp_last_log_id' );
    if ( $last_id ) {
        $logs = get_option( 'mijn_smtp_email_logs', array() );
        foreach ( $logs as &$log ) {
            if ( $log['id'] === $last_id ) {
                $log['status']     = 'failed';
                $log['error']      = $foutmelding;
                $log['server_log'] = $brink_smtp_global_debug;
                break;
            }
        }
        unset( $log );
        update_option( 'mijn_smtp_email_logs', $logs, false );
    }
}

/**
 * 6/7. Periodieke rapportmail (interval instelbaar via suggestie #32)
 */
add_action( 'mijn_smtp_wekelijks_rapport_event', 'mijn_smtp_verstuur_wekelijks_rapport' );
function mijn_smtp_verstuur_wekelijks_rapport() {
    $opties    = get_option( 'mijn_smtp_options', array() );
    $interval  = ! empty( $opties['rapport_interval'] ) ? $opties['rapport_interval'] : 'weekly';
    $labels    = array( 'daily' => 'Dagelijks', 'weekly' => 'Wekelijks', 'monthly' => 'Maandelijks' );
    $aantal    = (int) get_option( 'mijn_smtp_wekelijkse_teller', 0 );
    $beheerder = get_option( 'admin_email' );
    $sitenaam  = get_bloginfo( 'name' );

    $onderwerp = ( $labels[ $interval ] ?? 'Periodiek' ) . ' SMTP Rapport - ' . $sitenaam;
    $bericht   = "Hallo,\n\nDit is je rapportage voor '$sitenaam'.\nAantal verzonden e-mails sinds het vorige rapport: $aantal\n\nGroet,\nBrink SMTP";

    // v3.2 - suggestie #10: optioneel de mislukte verzendingen (met reden) meesturen.
    // Beperkt tot wat het logboek nog heeft (max. 7 dagen bewaartermijn), ongeacht het
    // gekozen rapport-interval.
    if ( ! empty( $opties['rapport_inclusief_mislukt'] ) ) {
        $logs = get_option( 'mijn_smtp_email_logs', array() );
        $mislukt = array_values( array_filter( $logs, function( $log ) {
            return isset( $log['status'] ) && $log['status'] === 'failed';
        } ) );

        if ( ! empty( $mislukt ) ) {
            $bericht .= "\n\nMislukte verzendingen (laatste 7 dagen, max. 20 getoond):\n";
            foreach ( array_slice( $mislukt, 0, 20 ) as $log ) {
                $bericht .= '- ' . wp_date( 'd-m-Y H:i', $log['time'] ) . ' naar ' . $log['to'] . ': ' . $log['error'] . "\n";
            }
        } else {
            $bericht .= "\n\nEr waren geen mislukte verzendingen in de afgelopen 7 dagen.";
        }
    }

    wp_mail( $beheerder, $onderwerp, $bericht );
    update_option( 'mijn_smtp_wekelijkse_teller', 0, false );
}

/**
 * v3.1: losse, uitgestelde afhandeling van de volumewaarschuwing-mail (zie de bugfix-
 * toelichting bij mijn_smtp_verwerk_mail_log hierboven).
 */
add_action( 'mijn_smtp_volume_alert_event', 'mijn_smtp_verstuur_volume_alert', 10, 2 );
function mijn_smtp_verstuur_volume_alert( $aantal, $drempel ) {
    wp_mail(
        get_option( 'admin_email' ),
        'Ongebruikelijk hoog e-mailvolume - ' . get_bloginfo( 'name' ),
        "Er zijn vandaag al {$aantal} e-mails verzonden via Brink Multimedia SMTP, meer dan de ingestelde drempel van {$drempel}.\n\nDit kan wijzen op misbruik van je website. Controleer het logboek in het dashboard."
    );
}

/**
 * 8. DE MOTOR: koppel instellingen aan de e-mailfunctie
 */

function mijn_smtp_get_ms_access_token() {
    // v3.1 - bugfix: hergebruik een eerder opgehaald token zolang het geldig is,
    // i.p.v. bij ELKE verzonden mail een nieuw netwerkverzoek naar Microsoft te doen.
    $gecached = get_transient( 'mijn_smtp_ms_access_token_cache' );
    if ( $gecached ) {
        return $gecached;
    }

    $opties = get_option( 'mijn_smtp_options', array() );
    if ( empty( $opties['ms_refresh_token'] ) || empty( $opties['ms_client_id'] ) || empty( $opties['ms_client_secret'] ) ) {
        return false;
    }
    $tenant = ! empty( $opties['ms_tenant_id'] ) ? $opties['ms_tenant_id'] : 'common';
    $url = 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/token';

    $response = wp_remote_post( $url, array(
        'body' => array(
            'client_id'     => $opties['ms_client_id'],
            'client_secret' => $opties['ms_client_secret'],
            'refresh_token' => $opties['ms_refresh_token'],
            'grant_type'    => 'refresh_token',
        ),
        'timeout' => 15,
    ) );

    if ( ! is_wp_error( $response ) ) {
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $data['error'] ) ) {
            set_transient( 'mijn_smtp_ms_token_error', $data['error_description'] ?? $data['error'], 60 );
            return false;
        }
        if ( isset( $data['access_token'] ) ) {
            if ( isset( $data['refresh_token'] ) && $data['refresh_token'] !== $opties['ms_refresh_token'] ) {
                $opties['ms_refresh_token'] = $data['refresh_token'];
                update_option( 'mijn_smtp_options', $opties, false );
            }
            $levensduur = isset( $data['expires_in'] ) ? max( 60, (int) $data['expires_in'] - 120 ) : 3300;
            set_transient( 'mijn_smtp_ms_access_token_cache', $data['access_token'], $levensduur );
            return $data['access_token'];
        }
    }
    return false;
}

/**
 * v3.0 - suggestie #1: Google access token ophalen via refresh token.
 * v3.1: ook hier het token cachen (zie bugfix-toelichting bij de Microsoft-variant).
 */
function mijn_smtp_get_google_access_token() {
    $gecached = get_transient( 'mijn_smtp_google_access_token_cache' );
    if ( $gecached ) {
        return $gecached;
    }

    $opties = get_option( 'mijn_smtp_options', array() );
    if ( empty( $opties['google_refresh_token'] ) || empty( $opties['google_client_id'] ) || empty( $opties['google_client_secret'] ) ) {
        return false;
    }

    $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
        'body' => array(
            'client_id'     => $opties['google_client_id'],
            'client_secret' => $opties['google_client_secret'],
            'refresh_token' => $opties['google_refresh_token'],
            'grant_type'    => 'refresh_token',
        ),
        'timeout' => 15,
    ) );

    if ( ! is_wp_error( $response ) ) {
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $data['error'] ) ) {
            set_transient( 'mijn_smtp_google_token_error', $data['error_description'] ?? $data['error'], 60 );
            return false;
        }
        if ( isset( $data['access_token'] ) ) {
            // Let op: Google geeft bij een refresh_token-grant meestal geen nieuwe refresh_token terug.
            $levensduur = isset( $data['expires_in'] ) ? max( 60, (int) $data['expires_in'] - 120 ) : 3300;
            set_transient( 'mijn_smtp_google_access_token_cache', $data['access_token'], $levensduur );
            return $data['access_token'];
        }
    }
    return false;
}

function mijn_smtp_redact_debug_regel( $regel ) {
    if ( preg_match( '/\b(AUTH|Authorization|Bearer|XOAUTH2)\b/i', $regel ) ) {
        return '[GEREDIGEERD - regel kan inloggegevens bevatten]';
    }
    if ( preg_match( '/^[A-Za-z0-9+\/=]{24,}$/', trim( $regel ) ) ) {
        return '[GEREDIGEERD - mogelijk gecodeerde inloggegevens]';
    }
    return $regel;
}

/**
 * FEATURE (v3.2 - suggestie #11): best-effort SPF/DKIM-controle voor het ingestelde
 * afzenderdomein. Bewust GEEN automatische controle op elke pagina-load (DNS-lookups
 * kunnen traag of geblokkeerd zijn op sommige hosting) — alleen op handmatig verzoek
 * via een knop op de instellingenpagina, met 24 uur caching.
 */
function mijn_smtp_dns_check( $domein, $methode, $forceer = false ) {
    $cache_sleutel = 'mijn_smtp_dns_check_' . md5( $domein . '|' . $methode );

    if ( ! $forceer ) {
        $gecached = get_transient( $cache_sleutel );
        return false !== $gecached ? $gecached : false;
    }

    if ( ! function_exists( 'dns_get_record' ) ) {
        return false;
    }

    $resultaat = array( 'spf' => false, 'dkim' => false, 'dkim_selector' => '', 'tijd' => current_time( 'timestamp' ) );

    $txt_records = @dns_get_record( $domein, DNS_TXT );
    if ( is_array( $txt_records ) ) {
        foreach ( $txt_records as $record ) {
            if ( isset( $record['txt'] ) && stripos( $record['txt'], 'v=spf1' ) !== false ) {
                $resultaat['spf'] = true;
                break;
            }
        }
    }

    // DKIM: best-effort op basis van bekende standaard-selectors per methode. Een
    // negatief resultaat betekent niet per se een probleem (een eigen selector is ook mogelijk).
    $selectoren = array(
        'microsoft' => array( 'selector1', 'selector2' ),
        'google'    => array( 'google' ),
        'sendgrid'  => array( 's1', 's2' ),
    );
    if ( isset( $selectoren[ $methode ] ) ) {
        foreach ( $selectoren[ $methode ] as $selector ) {
            $dkim_records = @dns_get_record( $selector . '._domainkey.' . $domein, DNS_TXT );
            if ( is_array( $dkim_records ) && ! empty( $dkim_records ) ) {
                $resultaat['dkim'] = true;
                $resultaat['dkim_selector'] = $selector;
                break;
            }
        }
    }

    set_transient( $cache_sleutel, $resultaat, DAY_IN_SECONDS );
    return $resultaat;
}

/**
 * FEATURE (v3.2 - suggestie #20): klein overzicht van de laatste GitHub-releases,
 * met dezelfde caching-aanpak (12 uur / 15 minuten bij storing) als de auto-updater.
 */
function mijn_smtp_haal_recente_releases_op() {
    $cache = get_transient( 'mijn_smtp_recente_releases_cache' );
    if ( false !== $cache ) {
        return $cache;
    }

    $response = wp_remote_get( 'https://api.github.com/repos/Brinkmulti/Smtp/releases?per_page=3', array(
        'headers' => array( 'Accept' => 'application/vnd.github.v3+json' ),
        'timeout' => 8,
    ) );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        set_transient( 'mijn_smtp_recente_releases_cache', array(), 15 * MINUTE_IN_SECONDS );
        return array();
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    $releases = array();
    if ( is_array( $data ) ) {
        foreach ( array_slice( $data, 0, 3 ) as $release ) {
            $eerste_regel = '';
            if ( ! empty( $release['body'] ) ) {
                $regels = preg_split( '/\r\n|\r|\n/', trim( $release['body'] ) );
                foreach ( $regels as $regel ) {
                    $regel = trim( $regel, "# \t" );
                    if ( $regel !== '' ) {
                        $eerste_regel = $regel;
                        break;
                    }
                }
            }
            $releases[] = array(
                'tag'   => isset( $release['tag_name'] ) ? $release['tag_name'] : '',
                'datum' => isset( $release['published_at'] ) ? $release['published_at'] : '',
                'titel' => $eerste_regel,
                'url'   => isset( $release['html_url'] ) ? $release['html_url'] : '',
            );
        }
    }

    set_transient( 'mijn_smtp_recente_releases_cache', $releases, 12 * HOUR_IN_SECONDS );
    return $releases;
}

add_action( 'phpmailer_init', 'mijn_smtp_verwerk_verzending', 999 );
function mijn_smtp_verwerk_verzending( $phpmailer ) {
    global $brink_smtp_global_debug;
    $brink_smtp_global_debug = '';

    $opties = get_option( 'mijn_smtp_options', array() );

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
            mijn_smtp_registreer_oauth_provider_class();
            $phpmailer->setOAuth( new Brink_SMTP_OAuth_Provider( $from_email, $access_token ) );
        }
    }
    // METHODE 3: GOOGLE OAUTH (v3.0)
    elseif ( $methode === 'google' && ! empty( $opties['google_refresh_token'] ) ) {
        $phpmailer->isSMTP();
        $phpmailer->Host       = 'smtp.gmail.com';
        $phpmailer->Port       = 587;
        $phpmailer->SMTPSecure = 'tls';
        $phpmailer->SMTPAuth   = true;
        $phpmailer->AuthType   = 'XOAUTH2';

        $from_email = ! empty( $opties['from_email'] ) ? $opties['from_email'] : get_option( 'admin_email' );
        $access_token = mijn_smtp_get_google_access_token();

        if ( $access_token ) {
            mijn_smtp_registreer_oauth_provider_class();
            $phpmailer->setOAuth( new Brink_SMTP_OAuth_Provider( $from_email, $access_token ) );
        }
    }
    // METHODE 4 (SendGrid) loopt niet via PHPMailer/SMTP, zie mijn_smtp_pre_wp_mail_intercept().
}

function mijn_smtp_registreer_oauth_provider_class() {
    if ( ! class_exists( 'Brink_SMTP_OAuth_Provider' ) ) {
        class Brink_SMTP_OAuth_Provider implements \PHPMailer\PHPMailer\OAuthTokenProvider {
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
}

/**
 * v3.0 - suggestie #2: SendGrid via de HTTP-API, buiten PHPMailer/SMTP om.
 * 'pre_wp_mail' draait vóór PHPMailer wordt aangeroepen en kan de verzending
 * volledig overnemen door true/false terug te geven i.p.v. null.
 */
add_filter( 'pre_wp_mail', 'mijn_smtp_pre_wp_mail_intercept', 10, 2 );
function mijn_smtp_pre_wp_mail_intercept( $return, $atts ) {
    $opties = get_option( 'mijn_smtp_options', array() );
    if ( ( $opties['methode'] ?? 'standaard' ) !== 'sendgrid' ) {
        return $return; // laat de normale SMTP-flow via phpmailer_init lopen
    }
    return mijn_smtp_verstuur_via_sendgrid( $atts );
}

function mijn_smtp_parse_headers( $headers ) {
    $resultaat = array( 'content_type' => 'text/plain', 'from_email' => '', 'from_name' => '', 'cc' => array(), 'bcc' => array() );
    if ( empty( $headers ) ) {
        return $resultaat;
    }
    if ( ! is_array( $headers ) ) {
        $headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
    }
    foreach ( $headers as $regel ) {
        if ( strpos( $regel, ':' ) === false ) {
            continue;
        }
        list( $naam, $waarde ) = array_map( 'trim', explode( ':', $regel, 2 ) );
        $naam_lower = strtolower( $naam );
        if ( 'content-type' === $naam_lower && stripos( $waarde, 'text/html' ) !== false ) {
            $resultaat['content_type'] = 'text/html';
        } elseif ( 'from' === $naam_lower ) {
            if ( preg_match( '/(.*)<(.+)>/', $waarde, $m ) ) {
                $resultaat['from_name']  = trim( $m[1], " \"'" );
                $resultaat['from_email'] = trim( $m[2] );
            } else {
                $resultaat['from_email'] = trim( $waarde );
            }
        } elseif ( 'cc' === $naam_lower ) {
            $resultaat['cc'] = array_map( 'trim', explode( ',', $waarde ) );
        } elseif ( 'bcc' === $naam_lower ) {
            $resultaat['bcc'] = array_map( 'trim', explode( ',', $waarde ) );
        }
    }
    return $resultaat;
}

function mijn_smtp_verstuur_via_sendgrid( $atts ) {
    $opties = get_option( 'mijn_smtp_options', array() );
    $api_key = isset( $opties['sendgrid_api_key'] ) ? $opties['sendgrid_api_key'] : '';
    if ( empty( $api_key ) ) {
        do_action( 'wp_mail_failed', new WP_Error( 'sendgrid_geen_api_key', 'Geen SendGrid API-key ingesteld.' ) );
        return false;
    }

    $to_ruw = $atts['to'];
    $to = is_array( $to_ruw ) ? $to_ruw : array_map( 'trim', explode( ',', $to_ruw ) );
    $parsed = mijn_smtp_parse_headers( $atts['headers'] );

    $from_email = ! empty( $opties['from_email'] ) ? $opties['from_email'] : get_option( 'admin_email' );
    $from_name  = ! empty( $opties['from_name'] ) ? $opties['from_name'] : get_bloginfo( 'name' );
    if ( ! empty( $parsed['from_email'] ) && empty( $opties['force_from_email'] ) ) {
        $from_email = $parsed['from_email'];
        $from_name  = $parsed['from_name'] ?: $from_name;
    }

    $personalizations = array( 'to' => array() );
    foreach ( $to as $adres ) {
        if ( $adres !== '' ) {
            $personalizations['to'][] = array( 'email' => $adres );
        }
    }
    if ( ! empty( $parsed['cc'] ) ) {
        $personalizations['cc'] = array_map( function( $a ) { return array( 'email' => $a ); }, array_filter( $parsed['cc'] ) );
    }
    if ( ! empty( $parsed['bcc'] ) ) {
        $personalizations['bcc'] = array_map( function( $a ) { return array( 'email' => $a ); }, array_filter( $parsed['bcc'] ) );
    }

    $payload = array(
        'personalizations' => array( $personalizations ),
        'from'             => array( 'email' => $from_email, 'name' => $from_name ),
        'subject'          => $atts['subject'],
        'content'          => array( array( 'type' => $parsed['content_type'], 'value' => $atts['message'] ) ),
    );

    $attachments = (array) $atts['attachments'];
    if ( ! empty( $attachments ) ) {
        $payload['attachments'] = array();
        foreach ( $attachments as $pad ) {
            if ( is_readable( $pad ) ) {
                $payload['attachments'][] = array(
                    'content'  => base64_encode( file_get_contents( $pad ) ),
                    'filename' => basename( $pad ),
                    'type'     => function_exists( 'mime_content_type' ) ? ( mime_content_type( $pad ) ?: 'application/octet-stream' ) : 'application/octet-stream',
                );
            }
        }
    }

    $response = wp_remote_post( 'https://api.sendgrid.com/v3/mail/send', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode( $payload ),
        'timeout' => 15,
    ) );

    if ( is_wp_error( $response ) ) {
        do_action( 'wp_mail_failed', new WP_Error( 'sendgrid_verzoek_mislukt', $response->get_error_message() ) );
        return false;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code >= 200 && $code < 300 ) {
        // v3.1 - bugfix: WordPress core vuurt 'wp_mail_succeeded' normaal automatisch af
        // ná phpmailer->send(), maar dat pad wordt hier volledig overgeslagen (SendGrid
        // loopt buiten PHPMailer om). Zonder dit bleef de "opeenvolgende fouten"-teller
        // bij gebruik van SendGrid voorgoed hangen, ook nadat verzending weer werkte.
        do_action( 'wp_mail_succeeded', $atts );
        return true;
    }

    do_action( 'wp_mail_failed', new WP_Error( 'sendgrid_api_fout', 'SendGrid API fout (' . $code . '): ' . wp_remote_retrieve_body( $response ) ) );
    return false;
}
