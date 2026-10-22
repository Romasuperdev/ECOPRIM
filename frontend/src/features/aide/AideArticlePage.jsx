import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ChevronLeft, ChevronRight } from 'lucide-react'
import MessageErreur from '../../components/ui/MessageErreur'
import { fetchAide, fetchArticleAide } from './aideApi'
import { iconeAide } from './icones'

/**
 * Un article d'Aide, avec le sommaire en colonne latérale (article courant surligné) et
 * les liens précédent / suivant dans sa catégorie.
 *
 * Le contenu est du TEXTE : React l'échappe, `whitespace-pre-line` garde les sauts de
 * ligne. Jamais d'interprétation HTML — ce qu'un rédacteur saisit ne peut pas devenir du
 * code exécuté chez le lecteur.
 */
export default function AideArticlePage() {
  const { id } = useParams()
  const article = useQuery({ queryKey: ['aide', 'article', id], queryFn: () => fetchArticleAide(id), retry: false })
  const sommaire = useQuery({ queryKey: ['aide'], queryFn: fetchAide })

  const introuvable = article.error?.response?.status === 404

  return (
    <div>
      <Link to="/aide" className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-muted hover:text-heading">
        <ArrowLeft size={16} /> Retour à l’aide
      </Link>

      <div className="grid gap-6 lg:grid-cols-[16rem_1fr]">
        <aside className="order-2 lg:order-1">
          <nav className="card rounded-2xl p-4 text-sm lg:sticky lg:top-24">
            {sommaire.data?.map((c) => {
              const Icone = iconeAide(c.icone)
              return (
                <div key={c.id} className="mb-3 last:mb-0">
                  <div className="mb-1 flex items-center gap-1.5 font-bold text-heading">
                    <Icone size={15} strokeWidth={2.5} style={{ color: 'var(--brand-accent)' }} />
                    {c.titre}
                  </div>
                  <ul className="space-y-0.5 pl-1">
                    {c.articles.map((a) => {
                      const courant = String(a.id) === String(id)
                      return (
                        <li key={a.id}>
                          <Link
                            to={`/aide/${a.id}`}
                            aria-current={courant ? 'page' : undefined}
                            className={`block rounded-lg px-2 py-1 ${courant ? 'font-bold' : 'text-muted hover:bg-[var(--surface-2)]'}`}
                            style={courant ? { background: 'var(--nav-active-bg)', color: 'var(--nav-active-ink)' } : undefined}
                          >
                            {a.titre}
                          </Link>
                        </li>
                      )
                    })}
                  </ul>
                </div>
              )
            })}
          </nav>
        </aside>

        <article className="order-1 min-w-0 lg:order-2">
          {article.isLoading && <p className="font-medium text-muted">Chargement…</p>}
          {introuvable && (
            <p className="card rounded-2xl px-5 py-8 text-center text-sm font-medium text-muted">
              Cet article n’existe pas ou n’est plus publié.
            </p>
          )}
          {article.error && !introuvable && <MessageErreur erreur={article.error} />}
          {article.data && (
            <div className="card rounded-2xl p-6">
              <div className="mb-1 flex flex-wrap items-center gap-2 text-xs font-semibold text-muted">
                <span>{article.data.categorie.titre}</span>
                {article.data.module && (
                  <span className="rounded-full px-2 py-0.5" style={{ background: 'var(--info-bg)', color: 'var(--info-text)' }}>
                    {article.data.module}
                  </span>
                )}
              </div>
              <h1 className="text-2xl font-bold text-heading">{article.data.titre}</h1>
              <div className="mt-4 whitespace-pre-line break-words text-sm leading-relaxed" style={{ color: 'var(--text)' }}>
                {article.data.contenu}
              </div>

              {(article.data.precedent || article.data.suivant) && (
                <div className="mt-8 flex flex-wrap justify-between gap-3 border-t pt-4" style={{ borderColor: 'var(--border)' }}>
                  {article.data.precedent ? (
                    <Link to={`/aide/${article.data.precedent.id}`} className="inline-flex max-w-[48%] items-center gap-1 text-sm font-semibold" style={{ color: 'var(--brand-accent)' }}>
                      <ChevronLeft size={16} className="shrink-0" />
                      <span className="truncate">{article.data.precedent.titre}</span>
                    </Link>
                  ) : <span />}
                  {article.data.suivant && (
                    <Link to={`/aide/${article.data.suivant.id}`} className="ml-auto inline-flex max-w-[48%] items-center gap-1 text-sm font-semibold" style={{ color: 'var(--brand-accent)' }}>
                      <span className="truncate">{article.data.suivant.titre}</span>
                      <ChevronRight size={16} className="shrink-0" />
                    </Link>
                  )}
                </div>
              )}
            </div>
          )}
        </article>
      </div>
    </div>
  )
}
