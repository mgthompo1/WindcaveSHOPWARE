/**
 * SW-Order Module Extension
 * Extends the order detail view to show Windcave payment information
 */

import './component/windcave-payment-details';

// Extend the order detail view
Shopware.Component.override('sw-order-detail-base', {
    template: `
        {% block sw_order_detail_base_content %}
            {% parent %}

            <windcave-payment-details
                v-if="order && order.transactions"
                :order="order"
            ></windcave-payment-details>
        {% endblock %}
    `
});
