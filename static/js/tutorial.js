/**
 * IPTV Detective Interactive Tutorial System
 * Provides guided tours for first-time users
 */

class TutorialSystem {
    constructor() {
        this.currentStep = 0;
        this.steps = [];
        this.active = false;
        this.overlay = null;
        this.tooltip = null;
        this.prompt = null;

        // Load tutorial progress from localStorage
        this.progress = JSON.parse(localStorage.getItem('iptv_tutorial_progress') || '{}');
    }

    // Initialize tutorial system
    init() {
        this.createOverlay();
        this.createTooltip();
        this.bindEvents();
        this.checkFirstTimeUser();
    }

    // Check if user should see tutorial
    checkFirstTimeUser() {
        const hasSeenTutorial = localStorage.getItem('iptv_tutorial_seen');
        const tutorialEnabled = localStorage.getItem('iptv_tutorial_enabled') !== 'false';

        if (!hasSeenTutorial && tutorialEnabled) {
            setTimeout(() => this.showTutorialPrompt(), 2000);
        }
    }

    // Show initial tutorial prompt
    showTutorialPrompt() {
        if (this.prompt) return; // Already showing

        this.prompt = document.createElement('div');
        this.prompt.className = 'tutorial-prompt';
        this.prompt.innerHTML = `
            <div class="tutorial-prompt-content">
                <h4><i class="bi bi-lightbulb me-2"></i>Welcome to IPTV Detective!</h4>
                <p>Would you like a quick guided tour of how to check your IPTV provider?</p>
                <div class="tutorial-prompt-buttons">
                    <button class="btn btn-primary" onclick="tutorial.startTutorial()">Yes, show me around!</button>
                    <button class="btn btn-outline-secondary" onclick="tutorial.skipTutorial()">Skip for now</button>
                </div>
            </div>
        `;
        document.body.appendChild(this.prompt);

        // Animate in
        setTimeout(() => {
            this.prompt.classList.add('show');
        }, 100);
    }

    // Start tutorial with specific steps
    startTutorial(tutorialName = 'provider-check') {
        this.active = true;
        this.currentStep = 0;
        this.loadTutorial(tutorialName);
        this.showStep(0);
        this.hidePrompt();
    }

    // Load tutorial definition
    loadTutorial(name) {
        this.steps = window.tutorialDefinitions?.[name] || [];
        if (this.steps.length === 0) {
            console.warn('Tutorial definition not found:', name);
        }
    }

    // Show specific tutorial step
    showStep(stepIndex) {
        if (stepIndex >= this.steps.length) {
            this.completeTutorial();
            return;
        }

        const step = this.steps[stepIndex];
        this.currentStep = stepIndex;

        // Highlight target element
        this.highlightElement(step.target);

        // Show tooltip
        this.showTooltip(step);

        // Update progress
        this.updateProgress(step.id);

        // Scroll element into view
        const element = document.querySelector(step.target);
        if (element) {
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    // Highlight target element
    highlightElement(selector) {
        // Remove previous highlights
        document.querySelectorAll('.tutorial-highlight').forEach(el => {
            el.classList.remove('tutorial-highlight');
        });

        const element = document.querySelector(selector);
        if (element) {
            element.classList.add('tutorial-highlight');
        }
    }

    // Show tooltip for current step
    showTooltip(step) {
        if (!this.tooltip) return;

        const isLastStep = this.currentStep === this.steps.length - 1;
        const actionText = step.action || (isLastStep ? 'Finish Tutorial' : 'Next');

        this.tooltip.innerHTML = `
            <div class="tutorial-tooltip-content">
                <div class="tutorial-step-counter">
                    <i class="bi bi-${isLastStep ? 'check-circle' : 'circle'} me-1"></i>
                    Step ${this.currentStep + 1} of ${this.steps.length}
                </div>
                <h5>${step.title}</h5>
                <p>${step.content}</p>
                <div class="tutorial-actions">
                    ${this.currentStep > 0 ? `<button class="btn btn-outline-secondary btn-sm" onclick="tutorial.previousStep()"><i class="bi bi-arrow-left me-1"></i>Previous</button>` : ''}
                    <button class="btn btn-primary btn-sm" onclick="tutorial.nextStep()"><i class="bi bi-arrow-right me-1"></i>${actionText}</button>
                    <button class="btn btn-link btn-sm text-muted" onclick="tutorial.skipTutorial()">Skip Tutorial</button>
                </div>
            </div>
        `;

        // Position tooltip
        this.positionTooltip(step.position || 'bottom');
        this.tooltip.style.display = 'block';
        this.overlay.style.display = 'block';

        // Animate in
        setTimeout(() => {
            this.tooltip.classList.add('show');
        }, 100);
    }

    // Position tooltip relative to highlighted element
    positionTooltip(position) {
        const highlight = document.querySelector('.tutorial-highlight');
        if (!highlight || !this.tooltip) return;

        const rect = highlight.getBoundingClientRect();
        const tooltipRect = this.tooltip.getBoundingClientRect();
        const viewport = {
            width: window.innerWidth,
            height: window.innerHeight
        };

        let top, left;

        // Calculate base position
        switch (position) {
            case 'top':
                top = rect.top - 10;
                left = rect.left + (rect.width / 2);
                break;
            case 'bottom':
                top = rect.bottom + 10;
                left = rect.left + (rect.width / 2);
                break;
            case 'left':
                top = rect.top + (rect.height / 2);
                left = rect.left - 10;
                break;
            case 'right':
                top = rect.top + (rect.height / 2);
                left = rect.right + 10;
                break;
            default:
                top = rect.bottom + 10;
                left = rect.left + (rect.width / 2);
        }

        // Adjust for viewport boundaries
        const tooltipWidth = 300; // Approximate width
        const tooltipHeight = 200; // Approximate height

        if (left + tooltipWidth > viewport.width) {
            left = viewport.width - tooltipWidth - 10;
        }
        if (left < 10) {
            left = 10;
        }
        if (top + tooltipHeight > viewport.height) {
            top = rect.top - tooltipHeight - 10;
        }
        if (top < 10) {
            top = 10;
        }

        this.tooltip.style.top = top + 'px';
        this.tooltip.style.left = left + 'px';
        this.tooltip.setAttribute('data-position', position);
    }

    // Navigation methods
    nextStep() {
        this.showStep(this.currentStep + 1);
    }

    previousStep() {
        if (this.currentStep > 0) {
            this.showStep(this.currentStep - 1);
        }
    }

    skipTutorial() {
        this.endTutorial();
        localStorage.setItem('iptv_tutorial_enabled', 'false');
    }

    completeTutorial() {
        this.endTutorial();
        localStorage.setItem('iptv_tutorial_seen', 'true');
        this.showCompletionMessage();
    }

    endTutorial() {
        this.active = false;
        this.hideOverlay();
        this.hideTooltip();
        this.removeHighlights();
    }

    // Progress tracking
    updateProgress(stepId) {
        this.progress[stepId] = true;
        localStorage.setItem('iptv_tutorial_progress', JSON.stringify(this.progress));
    }

    // UI creation methods
    createOverlay() {
        this.overlay = document.createElement('div');
        this.overlay.className = 'tutorial-overlay';
        this.overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 9997;
            display: none;
            backdrop-filter: blur(1px);
            transition: opacity 0.3s ease;
        `;
        document.body.appendChild(this.overlay);
    }

    createTooltip() {
        this.tooltip = document.createElement('div');
        this.tooltip.className = 'tutorial-tooltip';
        this.tooltip.style.cssText = `
            position: fixed;
            z-index: 9998;
            display: none;
            max-width: 320px;
            background: rgba(15, 23, 36, 0.95);
            border: 1px solid rgba(124, 246, 255, 0.3);
            border-radius: 12px;
            padding: 0;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(10px);
            transform: translateY(10px);
            opacity: 0;
            transition: all 0.3s ease;
        `;
        document.body.appendChild(this.tooltip);
    }

    // Utility methods
    hideOverlay() {
        if (this.overlay) {
            this.overlay.style.display = 'none';
        }
    }

    hideTooltip() {
        if (this.tooltip) {
            this.tooltip.classList.remove('show');
            setTimeout(() => {
                this.tooltip.style.display = 'none';
                this.tooltip.style.transform = 'translateY(10px)';
                this.tooltip.style.opacity = '0';
            }, 300);
        }
    }

    removeHighlights() {
        document.querySelectorAll('.tutorial-highlight').forEach(el => {
            el.classList.remove('tutorial-highlight');
        });
    }

    hidePrompt() {
        if (this.prompt) {
            this.prompt.classList.remove('show');
            setTimeout(() => {
                if (this.prompt.parentNode) {
                    this.prompt.parentNode.removeChild(this.prompt);
                }
                this.prompt = null;
            }, 300);
        }
    }

    showCompletionMessage() {
        const message = document.createElement('div');
        message.className = 'tutorial-completion';
        message.innerHTML = `
            <div class="alert alert-success alert-dismissible fade show shadow" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 10000; max-width: 400px;">
                <i class="bi bi-check-circle-fill me-2"></i>
                <strong>Tutorial Complete!</strong> You're now ready to check your IPTV provider.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        document.body.appendChild(message);

        // Show restart button
        const restartBtn = document.getElementById('tutorialRestartBtn');
        if (restartBtn) {
            restartBtn.style.display = 'block';
        }

        setTimeout(() => {
            if (message.parentNode) {
                message.remove();
            }
        }, 5000);
    }

    // Public API methods
    restartTutorial() {
        localStorage.removeItem('iptv_tutorial_seen');
        localStorage.removeItem('iptv_tutorial_progress');
        this.progress = {};
        this.startTutorial();
    }

    resetTutorial() {
        localStorage.removeItem('iptv_tutorial_seen');
        localStorage.removeItem('iptv_tutorial_enabled');
        localStorage.removeItem('iptv_tutorial_progress');
        this.progress = {};
    }

    // Bind global events
    bindEvents() {
        // Handle window resize
        window.addEventListener('resize', () => {
            if (this.active && this.tooltip.style.display !== 'none') {
                const step = this.steps[this.currentStep];
                if (step) {
                    this.positionTooltip(step.position || 'bottom');
                }
            }
        });

        // Handle escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.active) {
                this.skipTutorial();
            }
        });
    }
}

// Initialize tutorial system
const tutorial = new TutorialSystem();
document.addEventListener('DOMContentLoaded', () => tutorial.init());

// Export for global access
window.tutorial = tutorial;