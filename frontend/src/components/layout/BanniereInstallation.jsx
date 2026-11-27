import { useEffect, useState } from 'react'
import { Download, Share, SquarePlus, X } from 'lucide-react'
import {
  estInstallee, estIos, installationProposable, proposerInstallation, surInstallationProposable,
} from '../../lib/pwa'

const CLE_MASQUEE = 'nexora-installation-masquee'
const DELAI_RAPPEL_JOURS = 14

function masqueeRecemment() {
  try {
    const le = Number(localStorage.getItem(CLE_MASQUEE))
    return le > 0 && Date.now() - le < DELAI_RAPPEL_JOURS * 86_400_000
  } catch {
    return false
  }
}

/*
 * Invitation à installer l'application sur le téléphone.
 *
 * Android : un bouton « Installer » (l'invitation native du navigateur, captée par lib/pwa).
 * iPhone : aucune invitation native — on explique le geste « Partager → Sur l'écran
 * d'accueil ». C'est aussi la condition pour recevoir les notifications sur iPhone.
 * Fermée, elle revient au bout de deux semaines.
 */
export default function BanniereInstallation() {
  const [android, setAndroid] = useState(installationProposable())
  const [fermee, setFermee] = useState(masqueeRecemment())

  useEffect(() => surInstallationProposable(setAndroid), [])

  const ios = estIos() && !estInstallee()
  if (fermee || estInstallee() || (!ios && !android)) return null

  const fermer = () => {
    try { localStorage.setItem(CLE_MASQUEE, String(Date.now())) } catch { /* stockage refusé */ }
    setFermee(true)
  }

  return (
    <div
      className="mb-4 flex items-start gap-3 rounded-2xl border p-4 text-sm"
      style={{ background: 'var(--info-bg)', borderColor: 'var(--info-border)', color: 'var(--info-text)' }}
      role="region"
      aria-label="Installer l'application"
    >
      <img src={`${import.meta.env.BASE_URL}icones/icone-192.png`} alt="" className="h-10 w-10 shrink-0 rounded-xl" />
      <div className="min-w-0 flex-1">
        <p className="font-bold">Installez NEXORA sur votre téléphone</p>
        {ios ? (
          <p className="mt-1 leading-relaxed">
            Touchez <Share size={15} className="inline align-text-bottom" aria-label="Partager" /> en bas de
            Safari, puis <strong>« Sur l’écran d’accueil »</strong>{' '}
            <SquarePlus size={15} className="inline align-text-bottom" aria-hidden />. L’application s’ouvrira
            en plein écran et pourra vous envoyer les notifications de l’école.
          </p>
        ) : (
          <>
            <p className="mt-1">Accès en un geste depuis l’écran d’accueil, et notifications de l’école.</p>
            <button
              type="button"
              onClick={proposerInstallation}
              className="mt-2 inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-bold"
              style={{ background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}
            >
              <Download size={15} /> Installer
            </button>
          </>
        )}
      </div>
      <button type="button" onClick={fermer} className="shrink-0 p-1 opacity-70 hover:opacity-100" aria-label="Fermer">
        <X size={18} />
      </button>
    </div>
  )
}
