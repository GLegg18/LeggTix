<script setup lang="ts">
const { user } = useAuth();
useSeoMeta({ title: "LeggTix — Make room for a good time" });
</script>

<template>
  <main id="main" tabindex="-1">
    <section class="container hero">
      <div class="hero-copy">
        <p class="eyebrow">
          <span class="pink-rule" /> FOR THE LOVE OF SHOWING UP
        </p>
        <h1 tabindex="-1">
          Make room<br />for a <span class="hero-good">good</span><br /><span
            class="hero-time"
            >time<span class="brand-pink">.</span></span
          >
        </h1>
        <p class="hero-description">
          The big nights. The little discoveries.<br class="hidden sm:block" />
          The “glad we did that” moments.<br />Life’s better when you’re there.
        </p>
        <div class="mt-8 flex flex-wrap items-center gap-5">
          <NuxtLink
            :to="user ? '/account' : '/register'"
            class="button button-primary"
            >{{ user ? "Your account" : "Get started" }}
            <span aria-hidden="true">↗</span></NuxtLink
          ><a
            href="#how-it-works"
            class="text-link inline-flex items-center gap-3"
            >Meet LeggTix <span aria-hidden="true">↓</span></a
          >
        </div>
        <p class="hero-caption">
          <span class="status-dot" /> A little less scrolling. A little more
          living.
        </p>
      </div>
      <div
        class="hero-art"
        aria-label="Decorative LeggTix ticket artwork: you had to be there"
        role="img"
      >
        <span class="art-corner art-corner-top"
          >ADMIT ONE / GOOD TIMES AHEAD</span
        >
        <div class="ticket-card">
          <div class="ticket-top">
            <img
              src="/brand/leggtix-ticket.svg"
              alt=""
              width="35"
              height="35"
            /><span>LeggTix</span><span class="ticket-number">NO. 001</span>
          </div>
          <div class="ticket-graphic">
            <svg viewBox="0 0 320 250" aria-hidden="true">
              <g fill="none" stroke="currentColor" stroke-width="2">
                <ellipse
                  cx="160"
                  cy="126"
                  rx="125"
                  ry="59"
                  transform="rotate(-40 160 126)"
                />
                <ellipse
                  cx="160"
                  cy="126"
                  rx="125"
                  ry="59"
                  transform="rotate(40 160 126)"
                />
                <ellipse
                  cx="160"
                  cy="126"
                  rx="125"
                  ry="59"
                  transform="rotate(90 160 126)"
                />
              </g>
              <path
                fill="currentColor"
                d="m160 49 18 59 59 18-59 18-18 59-18-59-59-18 59-18Z"
              />
              <circle cx="58" cy="56" r="5" fill="currentColor" />
              <path
                fill="currentColor"
                d="m267 185 4 12 12 4-12 4-4 12-4-12-12-4 12-4Z"
              />
            </svg>
          </div>
          <p class="ticket-headline">YOU HAD<br />TO BE THERE<span>↗</span></p>
          <div class="ticket-perforation" />
          <div class="ticket-bottom">
            <span>LESS FOMO.<br />MORE “LET’S GO”.</span>
            <div class="barcode" />
          </div>
        </div>
        <span class="art-sticker"
          >GOOD<br />TIMES<br /><span aria-hidden="true">✳</span></span
        ><span class="art-corner art-corner-bottom"
          >SAVE SOME SPACE FOR THE UNEXPECTED ↗</span
        >
      </div>
    </section>
    <div class="manifesto-strip" aria-hidden="true">
      <div class="container flex flex-wrap justify-between gap-5">
        <span>SMALL PLANS.</span><span class="brand-pink">✳</span
        ><span>BIG MEMORIES.</span><span class="brand-pink">✳</span
        ><span>BE THERE.</span>
      </div>
    </div>
    <section id="how-it-works" class="container section-space scroll-mt-8">
      <div class="section-intro">
        <div>
          <p class="eyebrow brand-pink">HELLO, WE’RE LEGGTIX</p>
          <h2>More moments.<br />Less getting in the way.</h2>
        </div>
        <p class="text-muted leading-relaxed max-w-md">
          A home for events worth showing up for. We’re getting things ready.
          Your account is the first step towards being part of it.
        </p>
      </div>
      <div class="steps-grid">
        <article>
          <span class="step-number">01 / MAKE YOURSELF AT HOME</span>
          <h3>Start with you.</h3>
          <p>
            Create an account with your email. A simple starting point for
            whatever’s next.
          </p>
          <span class="step-symbol" aria-hidden="true">↗</span>
        </article>
        <article>
          <span class="step-number">02 / A QUICK HELLO</span>
          <h3>Check your inbox.</h3>
          <p>
            A short email code helps keep your account yours. No complicated
            setup.
          </p>
          <span class="step-symbol" aria-hidden="true">✳</span>
        </article>
        <article>
          <span class="step-number">03 / GOOD THINGS AHEAD</span>
          <h3>Be ready for more.</h3>
          <p>
            Event discovery and reservations are on their way. For now, get your
            account ready.
          </p>
          <span class="step-symbol" aria-hidden="true">◎</span>
        </article>
      </div>
    </section>
    <section class="container pb-20">
      <div class="join-banner">
        <div>
          <p class="eyebrow">YOUR NEXT GOOD TIME STARTS HERE</p>
          <h2>Count yourself in<span>.</span></h2>
          <p>Create your account. Leave a little room for the unexpected.</p>
        </div>
        <NuxtLink
          :to="user ? '/account' : '/register'"
          class="button button-ink"
          >{{ user ? "Go to your account" : "Create an account" }}
          <span aria-hidden="true">↗</span></NuxtLink
        >
      </div>
    </section>
  </main>
</template>
