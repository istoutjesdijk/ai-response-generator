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
    const DEFAULT_MAX_TOKENS_PARAM = 'max_tokens';

    // Thread and content limits
    const MAX_THREAD_ENTRIES = 20;
    const MAX_EXTRA_INSTRUCTIONS_LENGTH = 500;

    // Vision support defaults
    const DEFAULT_MAX_IMAGES = 5;
    const DEFAULT_MAX_IMAGE_SIZE_MB = 5;
    const OPENAI_MAX_IMAGES = 10;
    const ANTHROPIC_MAX_IMAGES = 100;

    // Supported image MIME types
    const SUPPORTED_IMAGE_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp'
    ];

    // HTTP status codes
    const HTTP_ERROR_THRESHOLD = 400;
}
