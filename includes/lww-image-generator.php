<?php
/**
 * Modul: KI & API Bild-Generator (v2.0)
 * 
 * UPDATE: Erweiterte Prompts für thematische Kategorie-Bilder und Standard-Steine für Farben.
 */
if (!defined('ABSPATH')) exit;

// ... AJAX Handler ...

function lww_generate_category_image_prompt($term_name, $taxonomy) {
    if ($taxonomy === 'lww_theme') {
        return sprintf(
            'A cinematic, high-quality LEGO themed wallpaper representing "%s". Action scene, plastic bricks, photorealistic, 8k resolution. No text.',
            $term_name
        );
    } elseif ($taxonomy === 'lww_part_category') {
        return sprintf(
            'A clean, isolated product photo of a group of LEGO parts belonging to category "%s". White background, technical sorting style.',
            $term_name
        );
    }
    return "LEGO image of $term_name";
}

function lww_generate_color_brick_prompt($color_name, $hex) {
    return sprintf(
        'A single standard LEGO 2x4 brick in the color "%s" (Hex #%s). Photorealistic, isolated on pure white background, studio lighting, plastic texture visible.',
        $color_name, $hex
    );
}

// ... rest of generation logic using these prompts ...
