/**
 * Windcave Settings Module
 *
 * Provides admin UI components for:
 * - API credential testing
 * - Webhook status and management
 * - Configuration overview
 */

import './component/windcave-api-test';
import './component/windcave-webhook-manager';

const { Module } = Shopware;

// Register the settings page under Extensions > Windcave
Module.register('windcave-settings', {
    type: 'plugin',
    name: 'Windcave',
    title: 'Windcave Payments',
    description: 'Windcave payment gateway settings and diagnostics',
    color: '#00A3E0',
    icon: 'default-basic-creditcardcvv',

    routes: {
        overview: {
            component: 'windcave-settings-overview',
            path: 'overview',
            meta: {
                parentPath: 'sw.settings.index',
                privilege: 'system.system_config'
            }
        }
    },

    settingsItem: {
        group: 'plugins',
        to: 'windcave.settings.overview',
        icon: 'default-basic-creditcardcvv',
        privilege: 'system.system_config'
    }
});

// Main overview component
Shopware.Component.register('windcave-settings-overview', {
    template: `
        <sw-page class="windcave-settings">
            <template #smart-bar-header>
                <h2>Windcave Payments</h2>
            </template>

            <template #content>
                <sw-card-view>
                    <sw-system-config
                        salesChannelSwitchable
                        domain="WindcaveSHOPWARE.config"
                    ></sw-system-config>

                    <windcave-api-test
                        :salesChannelId="currentSalesChannelId"
                    ></windcave-api-test>

                    <windcave-webhook-manager
                        :salesChannelId="currentSalesChannelId"
                    ></windcave-webhook-manager>
                </sw-card-view>
            </template>
        </sw-page>
    `,

    data() {
        return {
            currentSalesChannelId: null
        };
    }
});
