<?php
/*********************************************************************
 * AI Response Generator Plugin - Constants
 *********************************************************************/

class AIResponseGeneratorConstants {
    // Default API configuration
    const DEFAULT_MAX_TOKENS = 512;
    const DEFAULT_TEMPERATURE = 1;
    const DEFAULT_TIMEOUT = 60;
    const DEFAULT_ANTHROPIC_VERSION = '2023-06-01';
    const DEFAULT_MAX_TOKENS_PARAM = 'max_output_tokens';

    // Thread and content limits
    const DEFAULT_MAX_THREAD_ENTRIES = 20;
    const MAX_EXTRA_INSTRUCTIONS_LENGTH = 500;

    // Default system prompt
    const DEFAULT_SYSTEM_PROMPT = 'You are a helpful support agent. Draft a concise, professional reply. Quote the relevant ticket details when appropriate. Keep HTML minimal.';

    // Vision support defaults
    const DEFAULT_MAX_IMAGES = 5;
    const DEFAULT_MAX_IMAGE_SIZE_MB = 5;
    const OPENAI_MAX_IMAGES = 10;
    const ANTHROPIC_MAX_IMAGES = 100;

    // Supported image MIME types
    const SUPPORTED_IMAGE_TYPES = array(
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp'
    );

    // Supported document/file MIME types
    const SUPPORTED_FILE_TYPES = array(
        'application/pdf',
        'text/plain',
        'text/csv',
        'text/html',
        'text/markdown',
        'application/json',
        'application/xml',
        'text/xml',
    );

    // Default max files per request
    const DEFAULT_MAX_FILES = 5;

    // HTTP status codes
    const HTTP_ERROR_THRESHOLD = 400;

    /**
     * Default values for configuration fields
     */
    const DEFAULTS = array(
        'max_tokens' => self::DEFAULT_MAX_TOKENS,
        'temperature' => self::DEFAULT_TEMPERATURE,
        'timeout' => self::DEFAULT_TIMEOUT,
        'anthropic_version' => self::DEFAULT_ANTHROPIC_VERSION,
        'max_tokens_param' => self::DEFAULT_MAX_TOKENS_PARAM,
        'max_thread_entries' => self::DEFAULT_MAX_THREAD_ENTRIES,
        'max_images' => self::DEFAULT_MAX_IMAGES,
        'max_files' => self::DEFAULT_MAX_FILES,
        'max_attachment_size_mb' => self::DEFAULT_MAX_IMAGE_SIZE_MB,
    );

    /**
     * Get config value with fallback to default
     *
     * @param PluginConfig $cfg Plugin configuration
     * @param string $key Configuration key
     * @return mixed Configuration value or default
     */
    public static function get($cfg, $key) {
        $value = $cfg->get($key);

        // Return value if set and not empty
        if ($value !== null && $value !== '') {
            return $value;
        }

        // Return default if available
        return self::DEFAULTS[$key] ?? $value;
    }

    /**
     * Get config value as integer with fallback
     *
     * @param PluginConfig $cfg Plugin configuration
     * @param string $key Configuration key
     * @return int Integer value
     */
    public static function getInt($cfg, $key) {
        return intval(self::get($cfg, $key));
    }

    /**
     * Get config value as float with fallback
     *
     * @param PluginConfig $cfg Plugin configuration
     * @param string $key Configuration key
     * @return float Float value
     */
    public static function getFloat($cfg, $key) {
        return floatval(self::get($cfg, $key));
    }
}
