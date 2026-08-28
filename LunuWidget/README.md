# Lunu Widget - Shopware 6 Plugin

Accept cryptocurrency payments in your Shopware 6 store with the Lunu payment gateway.

## Features

- **Multiple Cryptocurrencies**: Accept Bitcoin, Ethereum, and other popular cryptocurrencies
- **Sandbox Mode**: Test the integration before going live
- **Easy Configuration**: Simple setup through the Shopware admin panel
- **Secure Payments**: All transactions are processed through the secure Lunu gateway
- **Multi-language Support**: Available in German and English
- **Shopware Compatibility**: Supports Shopware 6.4, 6.5, and 6.6

## Requirements

- PHP 7.4 or higher
- Shopware 6.4, 6.5, or 6.6
- A Lunu account with API credentials ([Sign up at lunu.io](https://lunu.io))

## Installation

### Via Shopware Admin Panel (Recommended)

1. Download the plugin zip file
2. Log in to your Shopware admin panel
3. Navigate to **Extensions > My Extensions**
4. Click **Upload extension**
5. Select the downloaded zip file
6. Click the toggle switch to activate the plugin

### Manual Installation

1. Download or clone this repository
2. Copy the `LunuWidget` folder to `custom/plugins/` in your Shopware installation
3. Execute the following commands:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate LunuWidget
   bin/console cache:clear
   ```

## Configuration

1. Navigate to **Extensions > My Extensions** in the Shopware admin panel
2. Find "Lunu Widget" and click the **...** button, then choose **Configure**
3. Select your **Sales Channel**
4. Enter your Lunu credentials:
   - **App ID**: Your Lunu App ID
   - **API Secret**: Your Lunu API Secret
5. Enable **Sandbox mode** if you want to test payments (optional)
6. Click **Save**

### Activate Payment Method

1. Go to **Sales Channels > [Your Sales Channel]** (e.g., "Storefront")
2. Scroll to the **Payment Methods** section
3. Add "Lunu Widget" to your active payment methods
4. Click **Save**

## Getting API Credentials

1. Sign up for a Lunu account at [lunu.io](https://lunu.io)
2. Complete the registration process
3. Navigate to your dashboard to find your App ID and API Secret
4. Copy these credentials to your Shopware configuration

## Usage

Once configured, customers will see "Lunu Widget" as a payment option during checkout. When selected:

1. Customer proceeds to checkout and selects "Lunu Widget" as payment method
2. Customer is redirected to the Lunu payment widget
3. Customer selects their preferred cryptocurrency and completes the payment
4. Customer is redirected back to your shop
5. Order status is automatically updated upon successful payment

## Testing

Use **Sandbox mode** to test the integration without processing real payments:

1. Enable "Sandbox mode" in the plugin configuration
2. Use the Lunu test environment to simulate payments
3. Verify that orders are created and updated correctly
4. Disable sandbox mode before going live

## Troubleshooting

### Payment method not visible

- Ensure the plugin is activated
- Check that the payment method is enabled for your sales channel
- Clear the cache: `bin/console cache:clear`

### Configuration not saving

- Verify your API credentials are correct
- Check that you have selected the correct sales channel
- Review Shopware logs for error messages

### Payments not completing

- Verify your App ID and API Secret are correct
- Check that you're not in sandbox mode when processing live payments
- Review the Shopware logs in `var/log/` for detailed error messages

## Support

- **Email**: support@lunu.io
- **Documentation**: [https://lunu.io/docs](https://lunu.io/docs)
- **Website**: [https://lunu.io](https://lunu.io)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of changes and version history.

## License

This plugin is licensed under the MIT License. See [LICENSE](LICENSE) for details.

## About Lunu

Lunu is a cryptocurrency payment gateway that enables businesses to accept digital currency payments easily and securely. Learn more at [lunu.io](https://lunu.io).

---

**Version**: 1.0.0  
**Author**: Lunu Solutions GmbH  
**Shopware Store**: Coming soon

