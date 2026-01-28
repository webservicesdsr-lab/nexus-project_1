<?php
add_theme_support('title-tag');

function nexus_shell_enqueue_styles() {
    wp_enqueue_style(
        'nexus-shell-style',
        get_stylesheet_uri(),
        [],
        filemtime( get_stylesheet_directory() . '/style.css' )
    );
}
add_action('wp_enqueue_scripts', 'nexus_shell_enqueue_styles');
