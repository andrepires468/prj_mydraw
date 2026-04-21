/** @type {import('tailwindcss').Config} */
import forms from '@tailwindcss/forms'

/**
 * Gera um color token baseado em uma CSS variable no formato `R G B`,
 * de forma que o Tailwind consiga aplicar modificadores de opacidade
 * (ex.: `bg-primary/20`, `text-error/30`) no mesmo token para os dois
 * temas (light/dark), alternando pelos valores definidos em `:root`
 * e em `.dark` no `src/assets/styles/base.css`.
 */
const c = (name) => `rgb(var(--color-${name}) / <alpha-value>)`

export default {
  darkMode: 'class',
  content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        'surface-container-low': c('surface-container-low'),
        'secondary': c('secondary'),
        'surface-container-lowest': c('surface-container-lowest'),
        'on-tertiary': c('on-tertiary'),
        'secondary-container': c('secondary-container'),
        'on-secondary-fixed': c('on-secondary-fixed'),
        'surface-dim': c('surface-dim'),
        'background': c('background'),
        'on-error-container': c('on-error-container'),
        'tertiary-container': c('tertiary-container'),
        'on-primary-fixed': c('on-primary-fixed'),
        'error': c('error'),
        'on-surface-variant': c('on-surface-variant'),
        'on-error': c('on-error'),
        'on-tertiary-container': c('on-tertiary-container'),
        'on-secondary-container': c('on-secondary-container'),
        'surface-container-highest': c('surface-container-highest'),
        'secondary-fixed': c('secondary-fixed'),
        'inverse-on-surface': c('inverse-on-surface'),
        'on-background': c('on-background'),
        'on-tertiary-fixed': c('on-tertiary-fixed'),
        'error-dim': c('error-dim'),
        'tertiary-dim': c('tertiary-dim'),
        'outline-variant': c('outline-variant'),
        'on-surface': c('on-surface'),
        'inverse-primary': c('inverse-primary'),
        'on-primary': c('on-primary'),
        'tertiary': c('tertiary'),
        'on-primary-fixed-variant': c('on-primary-fixed-variant'),
        'primary-fixed-dim': c('primary-fixed-dim'),
        'primary-container': c('primary-container'),
        'surface-container-high': c('surface-container-high'),
        'inverse-surface': c('inverse-surface'),
        'on-secondary-fixed-variant': c('on-secondary-fixed-variant'),
        'tertiary-fixed': c('tertiary-fixed'),
        'surface': c('surface'),
        'on-secondary': c('on-secondary'),
        'secondary-fixed-dim': c('secondary-fixed-dim'),
        'surface-tint': c('surface-tint'),
        'on-tertiary-fixed-variant': c('on-tertiary-fixed-variant'),
        'primary-dim': c('primary-dim'),
        'primary-fixed': c('primary-fixed'),
        'primary': c('primary'),
        'error-container': c('error-container'),
        'on-primary-container': c('on-primary-container'),
        'tertiary-fixed-dim': c('tertiary-fixed-dim'),
        'surface-variant': c('surface-variant'),
        'outline': c('outline'),
        'surface-container': c('surface-container'),
        'surface-bright': c('surface-bright'),
        'secondary-dim': c('secondary-dim'),
      },
      borderRadius: {
        DEFAULT: '0.125rem',
        lg: '0.25rem',
        xl: '0.5rem',
        full: '0.75rem',
      },
      fontFamily: {
        headline: ['Inter', 'sans-serif'],
        body: ['Inter', 'sans-serif'],
        label: ['Inter', 'sans-serif'],
      },
      boxShadow: {
        'nav-top': '0 -8px 32px rgba(5, 52, 92, 0.06)',
      },
    },
  },
  plugins: [forms],
}
