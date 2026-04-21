# Design System Specification: Atmospheric Fluidity

## 1. Overview & Creative North Star
**Creative North Star: The Digital Curator**

This design system is engineered to transform a standard image gallery into a high-end editorial experience. Instead of a rigid container-based layout, we treat the screen as an open atmosphere where imagery breathes. By moving away from traditional "boxed" UI, we adopt a philosophy of **Atmospheric Fluidity**. 

The system rejects the "template" look in favor of intentional asymmetry, varying tonal depths, and sophisticated glassmorphism. We prioritize the image above all else, using typography and UI components as subtle, functional annotations rather than distracting structures.

---

## 2. Colors & Tonal Architecture

Our palette is rooted in a "Deep Slate & Soft Indigo" spectrum. We avoid harsh contrasts, preferring the sophistication of layered neutrals.

### The "No-Line" Rule
**Explicit Instruction:** Designers are prohibited from using 1px solid borders for sectioning or containment. Structural boundaries must be defined exclusively through:
1.  **Background Color Shifts:** Placing a `surface-container-low` (#eff4ff) section against a `surface` (#f8f9ff) background.
2.  **Vertical Rhythm:** Using intentional white space to dictate grouping.
3.  **Tonal Transitions:** Subtle shifts between nested containers.

### Surface Hierarchy & Nesting
Treat the interface as a physical stack of translucent materials. 
- **Base Layer:** `surface` (#f8f9ff) / `surface-bright`.
- **Secondary Content Layer:** `surface-container-low` (#eff4ff).
- **Interactive/Floating Layer:** `surface-container-lowest` (#ffffff) for high-contrast cards.

### The "Glass & Gradient" Rule
To achieve a signature premium feel, use Glassmorphism for persistent floating elements (like navigation bars or action overlays). 
- **Backdrop Blur:** 20px - 40px.
- **Fill:** Use `surface` or `primary-container` at 70% opacity.
- **Signature Texture:** Apply a subtle linear gradient to main CTAs, transitioning from `primary` (#4a4bd7) to `primary-container` (#7073ff) at a 135-degree angle. This provides a "soul" to the UI that flat colors cannot mimic.

---

## 3. Typography: The Editorial Voice

We utilize **Inter** to bridge the gap between technical precision and editorial elegance.

- **Display (Large/Medium):** Used for gallery titles or immersive headers. High impact, tight letter-spacing (-0.02em).
- **Headline (Small/Medium):** Used for navigation and section titles. 
- **Body (Medium/Large):** Optimized for readability. Use `on_surface_variant` (#3d618c) for secondary descriptions to reduce visual noise.
- **Labels (Small/Medium):** All-caps with increased letter-spacing (0.05em) for a "metadata" aesthetic, perfect for image tags and technical specs.

The hierarchy is designed to be "Top-Heavy," using large `display-md` (2.75rem) type against much smaller `body-sm` (0.75rem) meta-text to create a sophisticated, high-contrast scale.

---

## 4. Elevation & Depth

We convey importance through **Tonal Layering** rather than structural shadows.

### The Layering Principle
Depth is achieved by stacking `surface-container` tiers. 
- **Example:** A `surface-container-lowest` (#ffffff) card sitting on a `surface-container-low` (#eff4ff) gallery feed creates a soft, natural "lift" without a single shadow.

### Ambient Shadows
Shadows should only be used for "Floating" or "Hover" states.
- **Spec:** Blur: 32px | Opacity: 6% | Color: `on_surface` (#05345c).
- **Intent:** Mimic natural ambient light. Avoid "drop shadows" that look like dark stickers.

### The "Ghost Border" Fallback
If a border is required for accessibility (e.g., in high-contrast mode), use a **Ghost Border**: `outline-variant` (#91b4e4) at 15% opacity. Never use 100% opaque borders.

---

## 5. Components

### Masonry Grid Cards
- **Structure:** No borders or visible containers by default. The image *is* the card.
- **Interactions:** On hover, apply a `surface-dim` (#c4dcff) overlay or a subtle scale-up (1.02x).
- **Corner Radius:** Use `xl` (0.75rem) for the primary gallery cards to soften the overall grid.

### Sleek Buttons
- **Primary:** Gradient fill (`primary` to `primary-container`). White text (`on_primary`). Radius: `full`.
- **Secondary:** Surface-tinted. Background: `secondary-container` (#e2e0f9). Text: `on_secondary_container` (#505064). 
- **Tertiary:** Pure text with an `label-md` style. No background until hover.

### Minimal Form Inputs
- **Style:** Abandon the "box." Use a `surface-container-low` background with a `sm` (0.125rem) radius.
- **Focus State:** Instead of a border, change the background to `surface-container-high` (#dce9ff) and animate the label upward using the `label-sm` scale.

### Chips & Filters
- **Selection Chips:** Use `secondary-container` for unselected and `primary` for selected.
- **Separation:** Forbid the use of divider lines between filters. Use 12px of horizontal spacing (`spacing-md`) to define the gap.

---

## 6. Do's and Don'ts

### Do
- **DO** use asymmetry in your masonry layouts. Not every image should be the same width/height.
- **DO** use `surface-container-highest` for tooltips to make them pop against the gallery.
- **DO** lean into "Pure White" (#ffffff) for Light Mode and "Inverse Surface" (#000f21) for Dark Mode to provide maximum breathing room.
- **DO** use `backdrop-blur` on all sticky headers.

### Don't
- **DON'T** use a 1px solid border to separate the sidebar from the main content. Use a background color shift.
- **DON'T** use pure black (#000000) for text. Use `on_surface` (#05345c) to maintain tonal harmony with the indigo accents.
- **DON'T** use standard "Drop Shadows" (Offset-Y: 4px, Blur: 4px). They feel dated and "out-of-the-box."
- **DON'T** crowd the imagery. If in doubt, add more white space.