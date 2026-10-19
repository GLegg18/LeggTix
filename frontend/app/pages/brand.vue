<script setup lang="ts">
useHead({ title: 'Logo studio · LeggTix', meta: [{ name: 'robots', content: 'noindex' }] })
const marks = [
  { letter: 'A', name: 'The ticket stub', file: 'ticket', description: 'A tilted ticket with an L cut into it. Direct, friendly and easy to recognise at favicon size.', note: 'Current site mark' },
  { letter: 'B', name: 'The live pulse', file: 'pulse', description: 'A ticket with a pulse running through it. A little more energy, with a nod to live music and shared experiences.', note: 'The energetic option' },
  { letter: 'C', name: 'The monogram', file: 'monogram', description: 'An interlocking L and T in a compact badge. Clean and flexible if the brand grows beyond events.', note: 'The minimal option' },
]
</script>

<template>
  <main id="main" tabindex="-1" class="brand-studio">
    <p class="brand-eyebrow">LEGGTIX / LOGO STUDIO</p>
    <h1 tabindex="-1">Same pink. Three personalities.</h1>
    <p class="brand-intro">Three original SVG marks to try on. Each works without a background, scales cleanly, and pairs with the LeggTix name. Option A is on the site for now.</p>
    <div class="brand-options">
      <article v-for="mark in marks" :key="mark.file" class="brand-option">
        <div class="brand-samples">
          <div class="brand-light"><img :src="`/brand/leggtix-${mark.file}.svg`" :alt="mark.name" width="96" height="96"><span>LeggTix</span></div>
          <div class="brand-dark"><img :src="`/brand/leggtix-${mark.file}.svg`" alt="" width="48" height="48"><span>LeggTix</span><img class="brand-tiny" :src="`/brand/leggtix-${mark.file}.svg`" alt="" width="24" height="24"></div>
        </div>
        <div class="brand-caption"><p class="brand-eyebrow">{{ mark.letter }} / {{ mark.note }}</p><h2>{{ mark.name }}</h2><p>{{ mark.description }}</p><a :href="`/brand/leggtix-${mark.file}.svg`" download>Download SVG <span aria-hidden="true">↗</span></a></div>
      </article>
    </div>
    <NuxtLink class="brand-back" to="/">← Back to LeggTix</NuxtLink>
  </main>
</template>

<style scoped>
.brand-studio{max-width:1200px;margin:0 auto;padding:clamp(2rem,6vw,6rem) 1.5rem;color:var(--text,#16121b)}
.brand-eyebrow{font:700 .72rem/1.5 ui-monospace,monospace;letter-spacing:.11em;text-transform:uppercase}
h1{font-size:clamp(2.1rem,5.2vw,4.6rem);line-height:1.05;letter-spacing:-.05em;margin:1rem 0;max-width:850px}
.brand-intro{font-size:1.1rem;line-height:1.7;max-width:690px;margin-bottom:3rem}
.brand-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1.25rem}
.brand-option{border:1px solid #aaa5b0;border-radius:1rem;overflow:hidden}
.brand-light{background:#fffafc;color:#16121b;min-height:230px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem}
.brand-light span{font-size:2rem;font-weight:800;letter-spacing:-.06em}
.brand-dark{background:#16121b;color:#fffafc;display:flex;align-items:center;gap:.7rem;padding:1.5rem}
.brand-dark span{font-weight:800;font-size:1.35rem;letter-spacing:-.06em}
.brand-tiny{margin-left:auto}
.brand-caption{padding:1.5rem}.brand-caption h2{font-size:1.4rem;font-weight:750;margin:.4rem 0 .75rem}.brand-caption p{line-height:1.65}
.brand-caption a,.brand-back{display:inline-block;font-weight:700;text-decoration:underline;text-underline-offset:4px;margin-top:1.3rem}
.brand-back{margin-top:2.5rem}a:focus-visible{outline:3px solid #ce006b;outline-offset:5px;border-radius:2px}
@media(max-width:850px){.brand-options{grid-template-columns:1fr}.brand-light{min-height:180px}}
</style>
