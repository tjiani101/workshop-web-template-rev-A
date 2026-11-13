# MINEX design system

## Direction

The reference pages establish a clear sequence: a full-width photographic banner, direct title and breadcrumb, large service photography, a decisive CTA, then a structured dark footer. MINEX keeps that rhythm and adapts the imagery and content to motorcycle maintenance. No car photography or automotive detailing services are used.

The identity combines restrained European workshop character with spacious, editorial page layouts. Content uses the graphite surfaces and deliberate alignment of the references, with a more modern and quieter hierarchy for everyday riders.

## Tokens

- **Graphite:** `#101214` page background and `#17191b` raised sections.
- **Titanium:** `#1d2023` panels and fine grey dividers.
- **Off-white:** `#f1f0ec` primary type.
- **Restrained red:** `#e21f31` for decisions, rules, and primary actions.
- **Type:** system sans-serif for reading; condensed display stack for short uppercase headings and service indices.
- **Spacing:** wide section margins on desktop, reduced in clear steps at 820px and 560px.
- **Photography:** local Unsplash-licensed motorcycle service and workshop photos with a consistent desaturated treatment and dark overlays.

## Components and behavior

- Navigation has direct links for services, packages, pricing, about, and contact; it collapses to an accessible menu on smaller screens.
- The homepage uses a photographic hero, brand explanation, dynamic category and service lists, package cards, a three-step service explanation, workshop imagery, and a booking CTA. Testimonials are omitted because none are real.
- The services page uses a photo banner with breadcrumb followed by service-category sections. Service cards form three columns on desktop, two on tablet, one on mobile.
- Package and detail views use separate hierarchy: component checklists and fitment notes instead of repeating the service grid.
- Buttons, links, form fields, error states, demo labels, and empty states share common tokens.
- Reveal transitions respect `prefers-reduced-motion`; content remains visible if IntersectionObserver is unavailable.

## Data presentation

Unconfigured prices and durations are explicitly shown as awaiting workshop confirmation. Local sample records carry a `Demo` label in local mode. No compatibility claim is shown until MINEX supplies verified fitment data. Contact details remain absent until configured; the form still accepts and stores a message.
