import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { CircleHelp, Search } from 'lucide-react'
import Input from '../../components/ui/Input'
import MessageErreur from '../../components/ui/MessageErreur'
import useDiffere from '../../hooks/useDiffere'
import { fetchAide, rechercherAide } from './aideApi'
import { iconeAide } from './icones'

/**
 * Aide — le sommaire : une recherche, puis les catégories et leurs articles.
 *
 * Ne montre que l'actif (le serveur ne rend rien d'autre). Une recherche de moins de deux
 * caractères n'interroge pas le serveur : elle ramènerait presque tout.
 */
export default function AidePage() {
  const [saisie, setSaisie] = useState('')
  const terme = useDiffere(saisie.trim())
  const recherche = terme.length >= 2

  const sommaire = useQuery({ queryKey: ['aide'], queryFn: fetchAide })
  const resultats = useQuery({
    queryKey: ['aide', 'recherche', terme],
    queryFn: () => rechercherAide(terme),
    enabled: recherche,
  })

  return (
    <div>
      <header className="mb-5">
        <h1 className="flex items-center gap-2.5 text-2xl font-bold text-heading">
          <CircleHelp size={24} strokeWidth={2.5} style={{ color: 'var(--brand-accent)' }} />
          Aide
        </h1>
        <p className="mt-1 max-w-3xl text-sm font-medium text-muted">
          Les pas à pas et explications de l’application, classés par thème.
        </p>
      </header>

      <div className="mb-6 max-w-xl">
        <Input
          icon={Search}
          type="search"
          placeholder="Rechercher dans l’aide…"
          value={saisie}
          onChange={(e) => setSaisie(e.target.value)}
          aria-label="Rechercher dans l’aide"
        />
      </div>

      {recherche ? (
        <section>
          <h2 className="mb-3 text-base font-bold text-heading">Résultats pour « {terme} »</h2>
          {resultats.isLoading && <p className="font-medium text-muted">Recherche…</p>}
          {resultats.error && <MessageErreur erreur={resultats.error} />}
          {resultats.data?.length === 0 && (
            <p className="card rounded-2xl px-5 py-8 text-center text-sm font-medium text-muted">
              Aucun article ne correspond à cette recherche.
            </p>
          )}
          <ul className="space-y-2">
            {resultats.data?.map((a) => (
              <li key={a.id}>
                <Link to={`/aide/${a.id}`} className="card survol-leger block rounded-2xl px-5 py-4">
                  <div className="text-xs font-semibold text-muted">{a.categorie.titre}</div>
                  <div className="font-bold text-heading">{a.titre}</div>
                  <p className="mt-1 text-sm text-muted">{a.extrait}</p>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ) : (
        <>
          {sommaire.isLoading && <p className="font-medium text-muted">Chargement…</p>}
          {sommaire.error && <MessageErreur erreur={sommaire.error} />}
          {sommaire.data?.length === 0 && (
            <p className="card rounded-2xl px-5 py-8 text-center text-sm font-medium text-muted">
              L’aide n’a pas encore été rédigée.
            </p>
          )}
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {sommaire.data?.map((c) => {
              const Icone = iconeAide(c.icone)
              return (
                <section key={c.id} className="card rounded-2xl p-5">
                  <h2 className="mb-3 flex items-center gap-2 text-base font-bold text-heading">
                    <Icone size={18} strokeWidth={2.5} style={{ color: 'var(--brand-accent)' }} />
                    {c.titre}
                  </h2>
                  {c.articles.length === 0 ? (
                    <p className="text-sm text-muted">Aucun article pour l’instant.</p>
                  ) : (
                    <ul className="space-y-1">
                      {c.articles.map((a) => (
                        <li key={a.id}>
                          <Link
                            to={`/aide/${a.id}`}
                            className="block rounded-lg px-2 py-1.5 text-sm font-medium text-heading hover:bg-[var(--surface-2)]"
                          >
                            {a.titre}
                          </Link>
                        </li>
                      ))}
                    </ul>
                  )}
                </section>
              )
            })}
          </div>
        </>
      )}
    </div>
  )
}
