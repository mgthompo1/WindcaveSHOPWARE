/**
 * SW-Order Module Extension
 * Extends the order detail view to show Windcave payment information
 */

import './component/windcave-payment-details';

// Import the Twig template for the extension
import template from './sw-order-detail-details-extension.html.twig';

const { Component } = Shopware;

// Extend the order detail details view (Shopware 6.6+)
Component.override('sw-order-detail-details', {
    template
});
