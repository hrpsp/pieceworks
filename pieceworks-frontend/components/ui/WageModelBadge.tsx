import * as React from "react"

import { WageModel, WAGE_MODEL_LABELS } from "@/types/pieceworks"

// ── Per-model visual config ───────────────────────────────────────────────────

const MODEL_CONFIG: Record<
  WageModel,
  { bg: string; color: string; icon: string }
> = {
  daily_grade: { bg: "#DBEAFE", color: "#1E3A8A", icon: "📅" },
  per_pair:    { bg: "#DCFCE7", color: "#14532D", icon: "📦" },
  hybrid:      { bg: "#FEF3C7", color: "#92400E", icon: "⚡" },
}

// ── Props ─────────────────────────────────────────────────────────────────────

export interface WageModelBadgeProps {
  model: WageModel
  showLabel?: boolean
}

// ── Component ─────────────────────────────────────────────────────────────────

export function WageModelBadge({ model, showLabel = true }: WageModelBadgeProps) {
  const { bg, color, icon } = MODEL_CONFIG[model]
  const label = showLabel ? WAGE_MODEL_LABELS[model] : model

  return (
    <span
      className="inline-flex items-center gap-1 uppercase font-bold"
      style={{
        backgroundColor:  bg,
        color:            color,
        fontSize:         "11px",
        fontWeight:       700,
        padding:          "2px 10px",
        borderRadius:     "20px",
        letterSpacing:    "0.03em",
      }}
    >
      <span>{icon}</span>
      <span>{label}</span>
    </span>
  )
}
