/* ================== Market Configuration ================== */

class MarketConfig {
    constructor() {
        this.apiBase = 'api';
        this.config = {
            data_providers: {
                series: 'auto',
                options: 'auto',
                realtime: true
            },
            indicators: {
                rsi: { periods: [14, 21], enabled: true },
                sma: { periods: [20, 50, 200], enabled: true },
                ema: { periods: [20, 40, 100, 200], enabled: true },
                macd: { fast: 12, slow: 26, signal: 9, enabled: false },
                bollinger: { period: 20, std: 2, enabled: false },
                volume: { period: 20, enabled: true }
            },
            timeframes: {
                intraday: ['1min', '5min', '15min', '60min'],
                daily: ['daily'],
                weekly: []
            },
            trading_params: {
                default_capital: 1000,
                default_tp: 5,
                default_sl: 3,
                trading_hours: '9:30-16:00'
            }
        };
        
        this.presets = {
            beginner: {
                indicators: { rsi: true, sma: true, volume: true },
                timeframes: ['15min', '60min', 'daily']
            },
            intermediate: {
                indicators: { rsi: true, sma: true, ema: true, macd: true, volume: true },
                timeframes: ['5min', '15min', '60min', 'daily']
            },
            expert: {
                indicators: { rsi: true, sma: true, ema: true, macd: true, bollinger: true, volume: true },
                timeframes: ['1min', '5min', '15min', '30min', '60min', 'daily', 'weekly']
            }
        };

        this.combinations = {
            scalping: ['1min', '5min', '15min'],
            intraday: ['5min', '15min', '60min'],
            swing: ['60min', 'daily']
        };

        this.init();
    }

    init() {
        this.loadConfiguration();
        this.setupEventListeners();
        this.updateIndicatorCards();
    }

    setupEventListeners() {
        // Preset buttons
        document.querySelectorAll('[data-preset]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                this.applyPreset(e.target.closest('[data-preset]').dataset.preset);
            });
        });

        // Combination buttons
        document.querySelectorAll('[data-combination]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                this.applyCombination(e.target.closest('[data-combination]').dataset.combination);
            });
        });

        // Indicator toggles
        document.querySelectorAll('[data-indicator]').forEach(toggle => {
            toggle.addEventListener('change', (e) => {
                this.updateIndicatorCard(e.target.dataset.indicator, e.target.checked);
            });
        });

        // Action buttons
        document.getElementById('btn-reset')?.addEventListener('click', () => this.resetConfiguration());
        document.getElementById('btn-save')?.addEventListener('click', () => this.saveConfiguration());
        document.getElementById('btn-test')?.addEventListener('click', () => this.testConfiguration());

        // Real-time toggle
        document.getElementById('realtime-enabled')?.addEventListener('change', (e) => {
            this.config.data_providers.realtime = e.target.checked;
        });

        // Provider changes
        document.getElementById('series-provider')?.addEventListener('change', (e) => {
            this.config.data_providers.series = e.target.value;
        });

        document.getElementById('options-provider')?.addEventListener('change', (e) => {
            this.config.data_providers.options = e.target.value;
        });

        // Trading parameters
        document.getElementById('default-capital')?.addEventListener('change', (e) => {
            this.config.trading_params.default_capital = parseFloat(e.target.value) || 1000;
        });

        document.getElementById('default-tp')?.addEventListener('change', (e) => {
            this.config.trading_params.default_tp = parseFloat(e.target.value) || 5;
        });

        document.getElementById('default-sl')?.addEventListener('change', (e) => {
            this.config.trading_params.default_sl = parseFloat(e.target.value) || 3;
        });

        document.getElementById('trading-hours')?.addEventListener('change', (e) => {
            this.config.trading_params.trading_hours = e.target.value;
        });

        // Timeframe checkboxes
        document.querySelectorAll('[data-timeframe]').forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                this.updateTimeframes();
            });
        });
    }

    applyPreset(presetName) {
        const preset = this.presets[presetName];
        if (!preset) return;

        // Clear all selections
        document.querySelectorAll('[data-preset]').forEach(card => {
            card.classList.remove('selected');
        });

        // Select current preset
        document.querySelector(`[data-preset="${presetName}"]`).classList.add('selected');

        // Apply indicator settings
        Object.keys(preset.indicators).forEach(indicator => {
            const toggle = document.querySelector(`[data-indicator="${indicator}"]`);
            if (toggle) {
                toggle.checked = preset.indicators[indicator];
                this.updateIndicatorCard(indicator, preset.indicators[indicator]);
            }
        });

        // Apply timeframe settings
        this.clearTimeframeSelections();
        preset.timeframes.forEach(timeframe => {
            const checkbox = document.querySelector(`[data-timeframe="${timeframe}"]`);
            if (checkbox) {
                checkbox.checked = true;
            }
        });

        this.updateTimeframes();
        this.showToast(`Configuración "${presetName}" aplicada`, 'success');
    }

    applyCombination(combinationName) {
        const combination = this.combinations[combinationName];
        if (!combination) return;

        // Clear all selections
        document.querySelectorAll('[data-combination]').forEach(card => {
            card.classList.remove('selected');
        });

        // Select current combination
        document.querySelector(`[data-combination="${combinationName}"]`).classList.add('selected');

        // Apply timeframe settings
        this.clearTimeframeSelections();
        combination.forEach(timeframe => {
            const checkbox = document.querySelector(`[data-timeframe="${timeframe}"]`);
            if (checkbox) {
                checkbox.checked = true;
            }
        });

        this.updateTimeframes();
        this.showToast(`Combinación "${combinationName}" aplicada`, 'success');
    }

    clearTimeframeSelections() {
        document.querySelectorAll('[data-timeframe]').forEach(checkbox => {
            checkbox.checked = false;
        });
    }

    updateIndicatorCard(indicator, enabled) {
        const card = document.querySelector(`[data-indicator="${indicator}"]`).closest('.indicator-card');
        if (enabled) {
            card.classList.add('enabled');
        } else {
            card.classList.remove('enabled');
        }
    }

    updateIndicatorCards() {
        document.querySelectorAll('[data-indicator]').forEach(toggle => {
            this.updateIndicatorCard(toggle.dataset.indicator, toggle.checked);
        });
    }

    updateTimeframes() {
        const selectedTimeframes = Array.from(document.querySelectorAll('[data-timeframe]:checked'))
            .map(checkbox => checkbox.dataset.timeframe);

        this.config.timeframes = {
            intraday: selectedTimeframes.filter(tf => ['1min', '5min', '15min', '30min', '60min'].includes(tf)),
            daily: selectedTimeframes.filter(tf => tf === 'daily'),
            weekly: selectedTimeframes.filter(tf => tf === 'weekly')
        };
    }

    loadConfiguration() {
        try {
            // Load from localStorage
            const saved = localStorage.getItem('market_config');
            if (saved) {
                this.config = { ...this.config, ...JSON.parse(saved) };
            }

            // Apply to UI
            this.applyConfigurationToUI();
        } catch (error) {
            console.warn('Error loading market configuration:', error);
        }
    }

    applyConfigurationToUI() {
        // Data providers
        const seriesProvider = document.getElementById('series-provider');
        const optionsProvider = document.getElementById('options-provider');
        const realtimeEnabled = document.getElementById('realtime-enabled');

        if (seriesProvider) seriesProvider.value = this.config.data_providers.series;
        if (optionsProvider) optionsProvider.value = this.config.data_providers.options;
        if (realtimeEnabled) realtimeEnabled.checked = this.config.data_providers.realtime;

        // Trading parameters
        const defaultCapital = document.getElementById('default-capital');
        const defaultTp = document.getElementById('default-tp');
        const defaultSl = document.getElementById('default-sl');
        const tradingHours = document.getElementById('trading-hours');

        if (defaultCapital) defaultCapital.value = this.config.trading_params.default_capital;
        if (defaultTp) defaultTp.value = this.config.trading_params.default_tp;
        if (defaultSl) defaultSl.value = this.config.trading_params.default_sl;
        if (tradingHours) tradingHours.value = this.config.trading_params.trading_hours;

        // Indicators
        Object.keys(this.config.indicators).forEach(indicator => {
            const toggle = document.querySelector(`[data-indicator="${indicator}"]`);
            if (toggle) {
                toggle.checked = this.config.indicators[indicator].enabled;
                this.updateIndicatorCard(indicator, this.config.indicators[indicator].enabled);
            }

            // Update periods
            if (indicator === 'rsi') {
                const periods = this.config.indicators[indicator].periods;
                document.querySelectorAll('[data-rsi-period]').forEach((input, index) => {
                    if (periods[index]) input.value = periods[index];
                });
            } else if (indicator === 'sma') {
                const periods = this.config.indicators[indicator].periods;
                document.querySelectorAll('[data-sma-period]').forEach((input, index) => {
                    if (periods[index]) input.value = periods[index];
                });
            } else if (indicator === 'ema') {
                const periods = this.config.indicators[indicator].periods;
                document.querySelectorAll('[data-ema-period]').forEach((input, index) => {
                    if (periods[index]) input.value = periods[index];
                });
            } else if (indicator === 'macd') {
                const config = this.config.indicators[indicator];
                document.querySelector('[data-macd-fast]').value = config.fast;
                document.querySelector('[data-macd-slow]').value = config.slow;
                document.querySelector('[data-macd-signal]').value = config.signal;
            } else if (indicator === 'bollinger') {
                const config = this.config.indicators[indicator];
                document.querySelector('[data-bb-period]').value = config.period;
                document.querySelector('[data-bb-std]').value = config.std;
            } else if (indicator === 'volume') {
                const config = this.config.indicators[indicator];
                document.querySelector('[data-volume-period]').value = config.period;
            }
        });

        // Timeframes
        const allTimeframes = [...this.config.timeframes.intraday, ...this.config.timeframes.daily, ...this.config.timeframes.weekly];
        document.querySelectorAll('[data-timeframe]').forEach(checkbox => {
            checkbox.checked = allTimeframes.includes(checkbox.dataset.timeframe);
        });
    }

    async saveConfiguration() {
        try {
            // Update configuration from UI
            this.updateConfigurationFromUI();

            // Save to localStorage
            localStorage.setItem('market_config', JSON.stringify(this.config));

            // Save to server
            const response = await fetch(`${this.apiBase}/settings_set_safe.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getToken()}`
                },
                body: JSON.stringify({
                    market_config: this.config
                })
            });

            if (response.ok) {
                this.showToast('Configuración guardada exitosamente', 'success');
            } else {
                this.showToast('Error al guardar en el servidor', 'error');
            }
        } catch (error) {
            console.error('Error saving market configuration:', error);
            this.showToast('Error al guardar configuración', 'error');
        }
    }

    updateConfigurationFromUI() {
        // Data providers
        this.config.data_providers.series = document.getElementById('series-provider')?.value || 'auto';
        this.config.data_providers.options = document.getElementById('options-provider')?.value || 'auto';
        this.config.data_providers.realtime = document.getElementById('realtime-enabled')?.checked || false;

        // Trading parameters
        this.config.trading_params.default_capital = parseFloat(document.getElementById('default-capital')?.value) || 1000;
        this.config.trading_params.default_tp = parseFloat(document.getElementById('default-tp')?.value) || 5;
        this.config.trading_params.default_sl = parseFloat(document.getElementById('default-sl')?.value) || 3;
        this.config.trading_params.trading_hours = document.getElementById('trading-hours')?.value || '9:30-16:00';

        // Update timeframes
        this.updateTimeframes();

        // Update indicators
        Object.keys(this.config.indicators).forEach(indicator => {
            const toggle = document.querySelector(`[data-indicator="${indicator}"]`);
            if (toggle) {
                this.config.indicators[indicator].enabled = toggle.checked;
            }
        });
    }

    resetConfiguration() {
        if (confirm('¿Estás seguro de que quieres restablecer la configuración a los valores por defecto?')) {
            // Reset to defaults
            this.config = {
                data_providers: {
                    series: 'auto',
                    options: 'auto',
                    realtime: true
                },
                indicators: {
                    rsi: { periods: [14, 21], enabled: true },
                    sma: { periods: [20, 50, 200], enabled: true },
                    ema: { periods: [20, 40, 100, 200], enabled: true },
                    macd: { fast: 12, slow: 26, signal: 9, enabled: false },
                    bollinger: { period: 20, std: 2, enabled: false },
                    volume: { period: 20, enabled: true }
                },
                timeframes: {
                    intraday: ['1min', '5min', '15min', '60min'],
                    daily: ['daily'],
                    weekly: []
                },
                trading_params: {
                    default_capital: 1000,
                    default_tp: 5,
                    default_sl: 3,
                    trading_hours: '9:30-16:00'
                }
            };

            this.applyConfigurationToUI();
            this.showToast('Configuración restablecida', 'info');
        }
    }

    async testConfiguration() {
        try {
            this.showToast('Probando configuración...', 'info');
            
            // Update configuration from UI
            this.updateConfigurationFromUI();

            // Test with a sample symbol
            const testSymbol = 'AAPL';
            const response = await fetch(`${this.apiBase}/time_series_safe.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getToken()}`
                },
                body: JSON.stringify({
                    symbol: testSymbol,
                    provider: this.config.data_providers.series,
                    resolutions: this.getAllTimeframes(),
                    indicators: this.getEnabledIndicators()
                })
            });

            if (response.ok) {
                const data = await response.json();
                this.showToast(`Configuración probada exitosamente con ${testSymbol}`, 'success');
                console.log('Test result:', data);
            } else {
                this.showToast('Error al probar configuración', 'error');
            }
        } catch (error) {
            console.error('Error testing configuration:', error);
            this.showToast('Error al probar configuración', 'error');
        }
    }

    getAllTimeframes() {
        return [
            ...this.config.timeframes.intraday,
            ...this.config.timeframes.daily,
            ...this.config.timeframes.weekly
        ];
    }

    getEnabledIndicators() {
        const indicators = {};
        Object.keys(this.config.indicators).forEach(indicator => {
            if (this.config.indicators[indicator].enabled) {
                indicators[indicator] = true;
            }
        });
        return indicators;
    }

    getToken() {
        return localStorage.getItem('auth_token');
    }

    showToast(message, type = 'info') {
        // Simple toast implementation
        const toast = document.createElement('div');
        toast.className = `fixed top-4 right-4 px-4 py-2 rounded-lg text-white z-50 ${
            type === 'success' ? 'bg-green-500' :
            type === 'error' ? 'bg-red-500' :
            type === 'warning' ? 'bg-yellow-500' :
            'bg-blue-500'
        }`;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            document.body.removeChild(toast);
        }, 3000);
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.MarketConfig = new MarketConfig();
});

// Export for global access
window.MarketConfigClass = MarketConfig;
