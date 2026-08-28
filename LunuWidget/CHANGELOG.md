# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-10-10

### Added
- Initial stable release
- Support for Shopware 6.4, 6.5, and 6.6
- Cryptocurrency payment integration via Lunu payment gateway
- Sandbox mode for testing
- Comprehensive error handling and logging
- PHPDoc documentation throughout the codebase

### Changed
- Improved error handling with proper logging instead of debug output
- Replaced deprecated `EntityRepositoryInterface` with `EntityRepository`
- Updated coding standards to follow PSR-12
- Enhanced security and data validation
- Improved API communication with better error messages

### Fixed
- Removed debug code (`echo`, `var_dump`, `exit`) from production code
- Fixed undefined variable references
- Fixed missing property declarations
- Improved exception handling

### Security
- Enhanced API response validation
- Added request timeout for API calls
- Improved error message sanitization

## [0.0.8] - Earlier versions

### Added
- Basic payment integration with Lunu
- Configuration options for App ID and API Secret
- Support for Shopware 6.4
- Payment method installation and lifecycle management
- Multi-language support (German and English)

---

For more information, visit [https://lunu.io](https://lunu.io)

