/**
 * A KAI Commuter KRL (new-generation trainset) seen from the front: glossy
 * black face, red side panels, light windshield with a wiper, silver band,
 * black skirt with coupler and a red plow. The destination (red on a grey
 * display) sits above the windshield. Decorative: the destination is also
 * written out next to it.
 */
export function KrlFront({
  destination,
  className,
}: {
  destination: string;
  className?: string;
}) {
  // The display shows the final destination only, without a "via ..." suffix.
  const text = destination.replace(/\s+via\s+.*$/i, "").toUpperCase();
  // Long names are squeezed to fit the display instead of overflowing it.
  const squeeze = text.length * 3.6 > 32;

  return (
    <svg
      viewBox="0 0 80 90"
      aria-hidden="true"
      focusable="false"
      className={className}
    >
      {/* Black face */}
      <path
        d="M9 16Q9 4 24 3H56Q71 4 71 16L73 66Q73 82 66 82H14Q7 82 7 66Z"
        fill="#0B1220"
      />
      <path d="M40 3v9" stroke="#334155" strokeWidth="0.6" />
      {/* Red side panels */}
      <path d="M10 14C8 28 5 46 6 63L14 60C13 44 13 28 16 12z" fill="#E4452B" />
      <path
        d="M70 14C72 28 75 46 74 63L66 60C67 44 67 28 64 12z"
        fill="#E4452B"
      />
      {/* Destination display */}
      <rect
        x="22"
        y="13"
        width="36"
        height="6.5"
        rx="1.2"
        fill="#D1D5DB"
        stroke="#9CA3AF"
        strokeWidth="0.5"
      />
      <text
        x="40"
        y="17.9"
        textAnchor="middle"
        fontSize="4.6"
        fontWeight="700"
        fill="#DC2626"
        letterSpacing={squeeze ? 0 : 0.3}
        textLength={squeeze ? 32 : undefined}
        lengthAdjust="spacingAndGlyphs"
        style={{ fontFamily: "ui-monospace, SFMono-Regular, Menlo, monospace" }}
      >
        {text}
      </text>
      {/* Windshield and wiper */}
      <rect x="16" y="22" width="48" height="25" rx="2.5" fill="#CBD5E1" />
      <rect x="16" y="22" width="48" height="7" rx="2.5" fill="#E2E8F0" />
      <path
        d="M26 45l6-20"
        stroke="#0B1220"
        strokeWidth="1"
        strokeLinecap="round"
      />
      <path
        d="M29 44l4-14"
        stroke="#0B1220"
        strokeWidth="0.6"
        strokeLinecap="round"
      />
      {/* Lights and logo */}
      <rect x="14" y="52" width="14" height="3.4" rx="1.5" fill="#1F2937" />
      <rect x="52" y="52" width="14" height="3.4" rx="1.5" fill="#1F2937" />
      <rect x="15" y="51" width="12" height="1" rx="0.5" fill="#F87171" />
      <rect x="53" y="51" width="12" height="1" rx="0.5" fill="#F87171" />
      <rect x="33" y="53" width="14" height="1.4" rx="0.7" fill="#EF4444" />
      <text
        x="40"
        y="60"
        textAnchor="middle"
        fontSize="4.2"
        fontWeight="800"
        fill="#fff"
      >
        KAI
      </text>
      <text
        x="40"
        y="62.8"
        textAnchor="middle"
        fontSize="2.2"
        fontWeight="600"
        fill="#fff"
      >
        Commuter
      </text>
      {/* Silver band */}
      <path d="M8 66Q40 62 72 66L73 72Q40 77 7 72Z" fill="#D1D5DB" />
      {/* Skirt, vents, coupler */}
      <rect x="14" y="74" width="9" height="5" rx="0.8" fill="#1F2937" />
      <rect x="57" y="74" width="9" height="5" rx="0.8" fill="#1F2937" />
      <rect x="32" y="73" width="16" height="8" rx="1.5" fill="#374151" />
      <rect x="37" y="74.5" width="6" height="4" rx="1" fill="#B8903C" />
      {/* Red plow */}
      <rect x="24" y="82" width="32" height="6" rx="0.8" fill="#E4452B" />
    </svg>
  );
}
