/**
 * Tutorial Definitions for IPTV Detective
 * Defines step-by-step guides for different user flows
 */

window.tutorialDefinitions = {
    'provider-check': [
        {
            id: 'welcome',
            title: 'Welcome to IPTV Detective! 🎯',
            content: 'This tool helps you analyze and compare IPTV providers. Let\'s walk through how to check your provider and see how it compares to others.',
            target: '.hero',
            position: 'bottom',
            action: 'Get Started'
        },
        {
            id: 'provider-name',
            title: 'Enter Your Provider Name',
            content: 'Start by entering the name of your IPTV provider or service. This helps us identify and categorize your submission.',
            target: '#name',
            position: 'bottom'
        },
        {
            id: 'provider-price',
            title: 'Set the Annual Price',
            content: 'Enter the yearly cost of your IPTV subscription. This information helps with price comparisons and value analysis.',
            target: '#price',
            position: 'bottom'
        },
        {
            id: 'xtream-server',
            title: 'Xtream Codes Server Details',
            content: 'Enter your Xtream Codes server hostname or IP address. This is required to connect and analyze your provider\'s content.',
            target: '#xt_host',
            position: 'right'
        },
        {
            id: 'xtream-credentials',
            title: 'Login Credentials',
            content: 'Provide your Xtream Codes username and password. We use these securely to check your account details and content availability.',
            target: '#xt_user',
            position: 'right'
        },
        {
            id: 'check-button',
            title: 'Analyze Your Provider',
            content: 'Click this button to start the analysis. We\'ll check your live categories, streams, series, and compare your provider with similar ones in our database.',
            target: 'button[type="submit"]',
            position: 'top',
            action: 'Start Analysis'
        },
        {
            id: 'results-section',
            title: 'Understanding Your Results',
            content: 'After analysis, you\'ll see detailed statistics about your provider including content counts, pricing comparisons, and similarity matches with other providers.',
            target: '#results',
            position: 'top',
            action: 'Finish Tutorial'
        }
    ],

    'advanced-features': [
        {
            id: 'help-modal',
            title: 'Get Help Anytime',
            content: 'Click the help button to access detailed information about how the comparison system works and what the results mean.',
            target: '.btn-help',
            position: 'top'
        },
        {
            id: 'discord-link',
            title: 'Community Support',
            content: 'Join our Discord community for support, to share provider experiences, and get help from other users.',
            target: '.fab-discord',
            position: 'left'
        }
    ]
};

/**
 * Tutorial utilities and helpers
 */
window.tutorialUtils = {
    // Check if element exists and is visible
    isElementReady: function(selector) {
        const element = document.querySelector(selector);
        return element && element.offsetParent !== null;
    },

    // Wait for element to be ready
    waitForElement: function(selector, timeout = 5000) {
        return new Promise((resolve, reject) => {
            if (this.isElementReady(selector)) {
                resolve(document.querySelector(selector));
                return;
            }

            const observer = new MutationObserver(() => {
                if (this.isElementReady(selector)) {
                    observer.disconnect();
                    resolve(document.querySelector(selector));
                }
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            setTimeout(() => {
                observer.disconnect();
                reject(new Error(`Element ${selector} not found within ${timeout}ms`));
            }, timeout);
        });
    },

    // Highlight element temporarily
    flashElement: function(selector, duration = 1000) {
        const element = document.querySelector(selector);
        if (!element) return;

        element.style.transition = 'all 0.3s ease';
        element.style.boxShadow = '0 0 0 3px #00ff00, 0 0 0 6px rgba(0, 255, 0, 0.3)';

        setTimeout(() => {
            element.style.boxShadow = '';
        }, duration);
    },

    // Track tutorial analytics
    trackEvent: function(event, data = {}) {
        // Simple analytics tracking - can be extended
        const eventData = {
            event: event,
            timestamp: new Date().toISOString(),
            tutorial_step: data.step || null,
            user_agent: navigator.userAgent,
            ...data
        };

        // Store in localStorage for now (could send to server)
        const events = JSON.parse(localStorage.getItem('tutorial_events') || '[]');
        events.push(eventData);

        // Keep only last 100 events
        if (events.length > 100) {
            events.splice(0, events.length - 100);
        }

        localStorage.setItem('tutorial_events', JSON.stringify(events));

        // Console log for debugging
        console.log('Tutorial Event:', eventData);
    }
};