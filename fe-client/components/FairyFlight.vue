<template>
  <div
    class="fairy"
    role="img"
    :aria-label="ariaLabel"
    :style="spriteStyle"
  />
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";

type SheetMeta = {
  frameWidth: number;
  frameHeight: number;
  columns: number;
  rows: number;
  frameCount: number;
  fps: number;
};

const props = withDefaults(
  defineProps<{
    /** Ширина одного кадра на экране, px */
    displayWidth?: number;
    ariaLabel?: string;
  }>(),
  {
    displayWidth: 200,
    ariaLabel: "Анимация феи в полёте",
  },
);

const defaults: SheetMeta = {
  frameWidth: 568,
  frameHeight: 855,
  columns: 4,
  rows: 2,
  frameCount: 8,
  fps: 12,
};

const { data: meta } = useFetch<SheetMeta>("/sprites/fairy-flight-sheet.json", {
  default: () => ({ ...defaults }),
});

const m = computed(() => ({ ...defaults, ...meta.value }));

const displayW = computed(() => props.displayWidth);
const displayH = computed(() =>
  Math.round(m.value.frameHeight * (displayW.value / m.value.frameWidth)),
);

const bgW = computed(() => m.value.columns * displayW.value);
const bgH = computed(() => m.value.rows * displayH.value);

const frame = ref(0);
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
  const tick = () => {
    const n = m.value.frameCount;
    frame.value = (frame.value + 1) % n;
  };
  const ms = Math.max(16, Math.round(1000 / m.value.fps));
  timer = setInterval(tick, ms);
});

onUnmounted(() => {
  if (timer) clearInterval(timer);
});

const bgPos = computed(() => {
  const col = frame.value % m.value.columns;
  const row = Math.floor(frame.value / m.value.columns);
  return `-${col * displayW.value}px -${row * displayH.value}px`;
});

const spriteStyle = computed(() => ({
  width: `${displayW.value}px`,
  height: `${displayH.value}px`,
  backgroundImage: "url(/sprites/fairy-flight-sheet.png)",
  backgroundRepeat: "no-repeat",
  backgroundSize: `${bgW.value}px ${bgH.value}px`,
  backgroundPosition: bgPos.value,
}));
</script>

<style scoped>
.fairy {
  flex-shrink: 0;
  filter: drop-shadow(0 12px 24px rgba(0, 0, 0, 0.45));
}
</style>
